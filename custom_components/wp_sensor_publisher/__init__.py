"""Инициализация интеграции WordPress Sensor Publisher."""
from __future__ import annotations

from datetime import timedelta
import logging
from typing import Any

from homeassistant.config_entries import ConfigEntry
from homeassistant.core import HomeAssistant, ServiceCall
from homeassistant.helpers.aiohttp_client import async_get_clientsession
from homeassistant.helpers.event import async_track_time_interval

from .const import (
    CONF_ENTITIES,
    CONF_SCAN_INTERVAL,
    CONF_TOKEN,
    CONF_URL,
    DEFAULT_SCAN_INTERVAL,
    DOMAIN,
)

_LOGGER = logging.getLogger(__name__)


async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    """Установка интеграции из записи Config Flow."""
    hass.data.setdefault(DOMAIN, {})

    async def publish_sensors(now=None) -> None:
        """Сбор состояний датчиков и отправка в WordPress."""
        config: dict[str, Any] = {**entry.data, **entry.options}
        url = config.get(CONF_URL)
        token = config.get(CONF_TOKEN)
        entity_ids = config.get(CONF_ENTITIES, [])

        if not url or not entity_ids:
            return

        sensors_payload = []
        for entity_id in entity_ids:
            state_obj = hass.states.get(entity_id)
            if state_obj is not None:
                sensors_payload.append(
                    {
                        "entity_id": entity_id,
                        "state": state_obj.state,
                        "unit": state_obj.attributes.get("unit_of_measurement", ""),
                        "name": state_obj.attributes.get("friendly_name", entity_id),
                    }
                )

        if not sensors_payload:
            return

        session = async_get_clientsession(hass)
        try:
            async with session.post(
                url,
                json={"sensors": sensors_payload},
                headers={"X-HA-Token": token},
                timeout=10,
            ) as response:
                if response.status != 200:
                    text = await response.text()
                    _LOGGER.warning(
                        "Ошибка публикации в WordPress (код %s): %s",
                        response.status,
                        text,
                    )
                else:
                    _LOGGER.debug(
                        "Успешно отправлено %d датчиков в WordPress",
                        len(sensors_payload),
                    )
        except Exception as err:
            _LOGGER.error("Не удалось отправить данные в WordPress: %s", err)

    # Получаем интервал в минутах
    interval_minutes = entry.options.get(
        CONF_SCAN_INTERVAL, entry.data.get(CONF_SCAN_INTERVAL, DEFAULT_SCAN_INTERVAL)
    )
    cancel_interval = async_track_time_interval(
        hass, publish_sensors, timedelta(minutes=int(interval_minutes))
    )

    # Служба для ручной немедленной отправки через 'Действия' (Developer Tools)
    async def handle_publish_service(call: ServiceCall) -> None:
        await publish_sensors()

    hass.services.async_register(
        DOMAIN, "publish_now", handle_publish_service
    )

    hass.data[DOMAIN][entry.entry_id] = {
        "cancel_interval": cancel_interval,
        "publish_fn": publish_sensors,
    }

    # Отправляем один раз сразу при старте
    hass.async_create_task(publish_sensors())

    entry.async_on_unload(entry.add_update_listener(async_reload_entry))
    return True


async def async_reload_entry(hass: HomeAssistant, entry: ConfigEntry) -> None:
    """Перезагрузка интеграции при изменении настроек в OptionsFlow."""
    await hass.config_entries.async_reload(entry.entry_id)


async def async_unload_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    """Выгрузка интеграции."""
    if entry.entry_id in hass.data[DOMAIN]:
        cancel_interval = hass.data[DOMAIN][entry.entry_id]["cancel_interval"]
        cancel_interval()
        hass.data[DOMAIN].pop(entry.entry_id)

    if not hass.data[DOMAIN]:
        hass.services.async_remove(DOMAIN, "publish_now")

    return True
