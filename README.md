# WordPress Sensor Publisher (Home Assistant Integration)

Интеграция для [Home Assistant](https://www.home-assistant.io/), позволяющая публиковать показания датчиков на удалённый сайт WordPress через защищённый REST API с полной визуальной настройкой через интерфейс Home Assistant (Config Flow / HACS).

## Возможности
- 100% настройка в интерфейсе Home Assistant без правки YAML-файлов.
- Удобный выбор датчиков через стандартный выпадающий список.
- Настройка интервала отправки (в минутах).
- Изменение датчиков и параметров на лету через кнопку «Настроить».
- Проверка доступности сайта и токена при подключении.
- Сервис `wp_sensor_publisher.publish_now` для ручной отправки.

## Структура репозитория
```text
├── custom_components/
│   └── wp_sensor_publisher/
│       ├── __init__.py
│       ├── config_flow.py
│       ├── const.py
│       ├── manifest.json
│       └── translations/
│           └── ru.json
├── hacs.json
└── README.md
```

## Установка через HACS
1. В Home Assistant откройте **HACS** -> **Интеграции**.
2. В верхнем правом углу нажмите три точки -> **Пользовательские репозитории** (*Custom repositories*).
3. Добавьте URL: `https://github.com/pelentron/wp_sensor_publisher` (или имя вашего репозитория), категория: **Интеграция** (*Integration*).
4. Нажмите **Загрузить** и перезапустите Home Assistant.
5. Перейдите в **Настройки** -> **Устройства и службы** -> **Добавить интеграцию** и выберите **WordPress Sensor Publisher**.
