#!/usr/bin/env bash

set -euo pipefail

readonly APP_DIR='/var/www/alexaidev_in_usr/data/www/alexaidev.info'
readonly APP_USER='alexaidev_in_usr'
readonly UNIT_SOURCE="${APP_DIR}/deploy/systemd"
readonly UNIT_TARGET='/etc/systemd/system'

if [[ "${EUID}" -ne 0 ]]; then
    echo 'Run this script as root.' >&2
    exit 1
fi

if [[ ! -f "${APP_DIR}/artisan" ]]; then
    echo "Laravel application not found in ${APP_DIR}." >&2
    exit 1
fi

if ! id "${APP_USER}" >/dev/null 2>&1; then
    echo "System user ${APP_USER} does not exist." >&2
    exit 1
fi

install -m 0644 "${UNIT_SOURCE}/reminder-bot-queue.service" "${UNIT_TARGET}/reminder-bot-queue.service"
install -m 0644 "${UNIT_SOURCE}/reminder-bot-scheduler.service" "${UNIT_TARGET}/reminder-bot-scheduler.service"
install -m 0644 "${UNIT_SOURCE}/reminder-bot-scheduler.timer" "${UNIT_TARGET}/reminder-bot-scheduler.timer"

systemctl daemon-reload
systemctl enable --now reminder-bot-queue.service
systemctl enable --now reminder-bot-scheduler.timer

systemctl --no-pager --full status reminder-bot-queue.service
systemctl --no-pager --full status reminder-bot-scheduler.timer
