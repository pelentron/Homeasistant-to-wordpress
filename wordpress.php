<?php
/**
 * Plugin Name: Home Assistant Sensor Publisher
 * Description: Принимает данные датчиков из Home Assistant через REST API и выводит их с помощью шорткодов.
 * Version: 1.0.0
 * Author: Home Assistant
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. Инициализация настроек
add_action('admin_init', function () {
    register_setting('ha_sp_settings', 'ha_sp_token');
    if (!get_option('ha_sp_token')) {
        update_option('ha_sp_token', wp_generate_password(32, false));
    }
});

// 2. Страница настроек в админке WordPress
add_action('admin_menu', function () {
    add_options_page(
        'Home Assistant Датчики',
        'HA Датчики',
        'manage_options',
        'ha-sensor-publisher',
        'ha_sp_render_admin_page'
    );
});

function ha_sp_render_admin_page() {
    if (isset($_POST['ha_sp_clear_data']) && check_admin_referer('ha_sp_clear_action')) {
        delete_option('ha_sp_sensors_data');
        echo '<div class="updated"><p>Данные датчиков очищены.</p></div>';
    }

    $token = get_option('ha_sp_token');
    $endpoint = rest_url('ha-publisher/v1/update');
    $data = get_option('ha_sp_sensors_data', []);
    ?>
    <div class="wrap">
        <h1>Home Assistant Sensor Publisher</h1>
        <p>Плагин принимает данные от домашнего сервера Home Assistant и сохраняет их для вывода на сайте.</p>

        <form method="post" action="options.php">
            <?php settings_fields('ha_sp_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">URL для Home Assistant:</th>
                    <td>
                        <input type="text" class="regular-text" readonly value="<?php echo esc_url($endpoint); ?>" style="width: 100%; max-width: 550px;" />
                        <p class="description">Укажите этот URL в конфигурации <code>rest_command</code> в Home Assistant.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Секретный токен (X-HA-Token):</th>
                    <td>
                        <input type="text" name="ha_sp_token" value="<?php echo esc_attr($token); ?>" style="width: 100%; max-width: 550px;" />
                        <p class="description">Ключ авторизации для заголовка <code>X-HA-Token</code>.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Сохранить токен'); ?>
        </form>

        <hr />
        <h2>Полученные данные датчиков (<?php echo count($data); ?>)</h2>
        <?php if (!empty($data)): ?>
            <table class="widefat fixed striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th>ID сущности (entity_id)</th>
                        <th>Название</th>
                        <th>Значение</th>
                        <th>Шорткод</th>
                        <th>Обновлено</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $id => $s): ?>
                        <tr>
                            <td><code><?php echo esc_html($id); ?></code></td>
                            <td><?php echo esc_html($s['name']); ?></td>
                            <td><strong><?php echo esc_html($s['state'] . ' ' . $s['unit']); ?></strong></td>
                            <td><code>[ha_sensor id="<?php echo esc_attr($id); ?>"]</code></td>
                            <td><?php echo esc_html($s['updated_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" style="margin-top: 15px;">
                <?php wp_nonce_field('ha_sp_clear_action'); ?>
                <input type="hidden" name="ha_sp_clear_data" value="1" />
                <button type="submit" class="button button-secondary" onclick="return confirm('Очистить сохранённые данные?');">Очистить кэш датчиков</button>
            </form>
        <?php else: ?>
            <p>Данные пока не поступали. Настройте отправку из Home Assistant.</p>
        <?php endif; ?>
    </div>
    <?php
}

// 3. Регистрация REST API эндпоинта
add_action('rest_api_init', function () {
    register_rest_route('ha-publisher/v1', '/update', [
        'methods'             => 'POST',
        'callback'            => 'ha_sp_handle_incoming_data',
        'permission_callback' => 'ha_sp_validate_token',
    ]);
});

function ha_sp_validate_token(WP_REST_Request $request) {
    $expected = get_option('ha_sp_token');
    $received = $request->get_header('x-ha-token');
    if (!$received) {
        $received = $request->get_param('token');
    }
    return !empty($expected) && !empty($received) && hash_equals($expected, $received);
}

function ha_sp_handle_incoming_data(WP_REST_Request $request) {
    $body = $request->get_json_params();
    if (empty($body)) {
        return new WP_Error('no_data', 'Пустой запрос', ['status' => 400]);
    }

    $sensors_data = get_option('ha_sp_sensors_data', []);
    if (!is_array($sensors_data)) {
        $sensors_data = [];
    }

    $items = isset($body['sensors']) && is_array($body['sensors']) ? $body['sensors'] : $body;
    $updated = 0;
    $now = current_time('mysql');

    foreach ($items as $key => $item) {
        if (!is_array($item)) continue;
        $entity_id = sanitize_text_field(isset($item['entity_id']) ? $item['entity_id'] : $key);
        if (empty($entity_id)) continue;

        $sensors_data[$entity_id] = [
            'entity_id'  => $entity_id,
            'state'      => isset($item['state']) ? sanitize_text_field($item['state']) : '',
            'unit'       => isset($item['unit']) ? sanitize_text_field($item['unit']) : '',
            'name'       => isset($item['name']) ? sanitize_text_field($item['name']) : $entity_id,
            'updated_at' => $now,
        ];
        $updated++;
    }

    update_option('ha_sp_sensors_data', $sensors_data, false);
    return rest_ensure_response(['success' => true, 'updated_count' => $updated]);
}

// 4. Шорткоды для вывода на страницах и в записях
// [ha_sensor id="sensor.temperature" unit="yes"]
add_shortcode('ha_sensor', function ($atts) {
    $atts = shortcode_atts(['id' => '', 'unit' => 'yes', 'fallback' => '—'], $atts);
    if (empty($atts['id'])) return '';

    $data = get_option('ha_sp_sensors_data', []);
    if (!isset($data[$atts['id']])) return esc_html($atts['fallback']);

    $s = $data[$atts['id']];
    $val = esc_html($s['state']);
    if ($atts['unit'] === 'yes' && !empty($s['unit'])) {
        $val .= ' ' . esc_html($s['unit']);
    }
    return $val;
});

// [ha_card id="sensor.temperature"] — красивая карточка датчика
add_shortcode('ha_card', function ($atts) {
    $atts = shortcode_atts(['id' => '', 'fallback' => 'Нет данных'], $atts);
    if (empty($atts['id'])) return '';

    $data = get_option('ha_sp_sensors_data', []);
    if (!isset($data[$atts['id']])) {
        return '<div style="padding:12px;border:1px dashed #cbd5e1;border-radius:8px;color:#94a3b8;display:inline-block;">' . esc_html($atts['fallback']) . '</div>';
    }

    $s = $data[$atts['id']];
    return '
    <div style="border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;display:inline-block;min-width:180px;background:#ffffff;box-shadow:0 4px 6px -1px rgba(0,0,0,0.06);margin:6px 6px 6px 0;font-family:inherit;">
        <div style="font-size:13px;color:#64748b;font-weight:500;margin-bottom:6px;">' . esc_html($s['name']) . '</div>
        <div style="font-size:26px;font-weight:700;color:#0f172a;line-height:1.2;">
            ' . esc_html($s['state']) . ' <span style="font-size:16px;font-weight:500;color:#475569;">' . esc_html($s['unit']) . '</span>
        </div>
        <div style="font-size:11px;color:#94a3b8;margin-top:8px;">Обновлено: ' . esc_html($s['updated_at']) . '</div>
    </div>';
});

// [ha_table] — сводная таблица всех полученных датчиков
add_shortcode('ha_table', function () {
    $data = get_option('ha_sp_sensors_data', []);
    if (empty($data)) return '<p>Данные датчиков пока не поступили.</p>';

    ob_start();
    ?>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">
        <thead>
            <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;text-align:left;">
                <th style="padding:10px 14px;">Датчик</th>
                <th style="padding:10px 14px;">Показание</th>
                <th style="padding:10px 14px;">Обновлено</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $s): ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:10px 14px;font-weight:500;"><?php echo esc_html($s['name']); ?></td>
                <td style="padding:10px 14px;font-weight:700;color:#0284c7;"><?php echo esc_html($s['state'] . ' ' . $s['unit']); ?></td>
                <td style="padding:10px 14px;color:#64748b;font-size:12px;"><?php echo esc_html($s['updated_at']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
});
