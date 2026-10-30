"""UI Config Flow и Options Flow для WordPress Sensor Publisher."""
from __future__ import annotations

import logging
from typing import Any
import voluptuous as vol

from homeassistant import config_entries
from homeassistant.core import callback
from homeassistant.helpers import selector
from homeassistant.helpers.aiohttp_client import async_get_clientsession

from .const import (
    CONF_ENTITIES,
    CONF_SCAN_INTERVAL,
    CONF_TOKEN,
    CONF_URL,
    DEFAULT_SCAN_INTERVAL,
    DOMAIN,
)

_LOGGER = logging.getLogger(__name__)


def build_schema(defaults: dict[str, Any] | None = None) -> vol.Schema:
    """Генерация схемы полей с удобными селекторами UI."""
    defaults = defaults or {}
    return vol.Schema(
        {
            vol.Required(
                CONF_URL,
                default=defaults.get(CONF_URL) or "https://your-site.com/wp-json/ha-publisher/v1/update",
            ): selector.TextSelector(
                selector.TextSelectorConfig(type=selector.TextSelectorType.URL)
            ),
            vol.Required(
                CONF_TOKEN,
                default=defaults.get(CONF_TOKEN) or "",
            ): selector.TextSelector(
                selector.TextSelectorConfig(type=selector.TextSelectorType.PASSWORD)
            ),
            vol.Required(
                CONF_SCAN_INTERVAL,
                default=int(defaults.get(CONF_SCAN_INTERVAL) or DEFAULT_SCAN_INTERVAL),
            ): selector.NumberSelector(
                selector.NumberSelectorConfig(
                    min=1,
                    max=1440,
                    step=1,
                    mode=selector.NumberSelectorMode.BOX,
                    unit_of_measurement="мин",
                )
            ),
            vol.Required(
                CONF_ENTITIES,
                default=defaults.get(CONF_ENTITIES) or [],
            ): selector.EntitySelector(
                selector.EntitySelectorConfig(
                    domain="sensor",
                    multiple=True,
                )
            ),
        }
    )


class WpPublisherConfigFlow(config_entries.ConfigFlow, domain=DOMAIN):
    """Первоначальное добавление интеграции через UI."""

    VERSION = 1

    async def async_step_user(
        self, user_input: dict[str, Any] | None = None
    ) -> config_entries.ConfigFlowResult:
        errors: dict[str, str] = {}

        if user_input is not None:
            # Проверяем доступность сайта
            session = async_get_clientsession(self.hass)
            try:
                # Отправляем тестовый пинг в API
                async with session.post(
                    user_input[CONF_URL],
                    json={"sensors": []},
                    headers={"X-HA-Token": user_input[CONF_TOKEN]},
                    timeout=8,
                ) as resp:
                    if resp.status in (200, 400):
                        # 200 = OK, 400 = пустой список (значит эндпоинт и токен верные)
                        return self.async_create_entry(
                            title="WordPress Сайт",
                            data=user_input,
                        )
                    if resp.status == 403:
                        errors["base"] = "invalid_auth"
                    else:
                        errors["base"] = "cannot_connect"
            except Exception:
                _LOGGER.warning("Не удалось выполнить тестовый запрос к WordPress API")
                errors["base"] = "cannot_connect"

        return self.async_show_form(
            step_id="user",
            data_schema=build_schema(user_input),
            errors=errors,
        )

    @staticmethod
    @callback
    def async_get_options_flow(
        config_entry: config_entries.ConfigEntry,
    ) -> config_entries.OptionsFlow:
        return WpPublisherOptionsFlowHandler(config_entry)


class WpPublisherOptionsFlowHandler(config_entries.OptionsFlow):
    """Изменение настроек (датчики, интервал, токен) через кнопку 'Настроить'."""

    def __init__(self, config_entry: config_entries.ConfigEntry | None = None) -> None:
        """Инициализация options flow."""
        super().__init__()
        if config_entry is not None:
            self._custom_entry = config_entry

    @property
    def _entry(self) -> config_entries.ConfigEntry | None:
        """Получение config entry совместимо со всеми версиями Home Assistant."""
        try:
            if hasattr(self, "config_entry") and self.config_entry is not None:
                return self.config_entry
        except Exception:
            pass
        return getattr(self, "_custom_entry", None)

    async def async_step_init(
        self, user_input: dict[str, Any] | None = None
    ) -> config_entries.ConfigFlowResult:
        if user_input is not None:
            return self.async_create_entry(title="", data=user_input)

        entry = self._entry
        current_values = {}
        if entry:
            current_values = {**entry.data, **entry.options}

        return self.async_show_form(
            step_id="init",
            data_schema=build_schema(current_values),
        )
