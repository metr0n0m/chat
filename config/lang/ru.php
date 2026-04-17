<?php

return [
    'errors.common.internal' => 'Внутренняя ошибка.',
    'errors.common.access_denied' => 'Нет доступа.',
    'errors.common.invalid_event' => 'Неизвестное событие.',
    'errors.common.invalid_request' => 'Некорректный запрос.',
    'errors.common.not_found' => 'Не найдено.',

    'errors.whisper.empty_or_too_long' => 'Сообщение пустое или слишком длинное.',
    'errors.whisper.user_not_in_room' => 'Получатель не в этой комнате.',
    'errors.whisper.not_in_room' => 'Вы не в этой комнате.',
    'errors.whisper.rate_limit' => 'Превышен лимит шёпота (5 в минуту).',

    'errors.numer.too_many_pending' => 'Слишком много ожидающих приглашений.',
    'errors.numer.user_not_found' => 'Пользователь не найден.',
    'errors.numer.invitation_not_found' => 'Приглашение не найдено или истекло.',
    'errors.numer.full' => 'Нумер заполнен.',
    'errors.numer.not_found' => 'Нумер не найден.',

    'errors.room.not_found' => 'Комната не найдена.',
    'errors.room.no_access' => 'Нет доступа к комнате.',
    'errors.room.not_in_room' => 'Вы не в этой комнате.',

    'errors.message.empty_or_too_long' => 'Сообщение пустое или слишком длинное.',
    'errors.message.rate_limit' => 'Слишком быстро. Подождите секунду.',

    'errors.user.self_action_forbidden' => 'Нельзя менять собственные административные права через админку.',
    'errors.user.only_lower_role' => 'Можно изменять только пользователей с более низкой ролью.',
    'errors.user.role_not_lower' => 'Нельзя назначить роль не ниже собственной.',
    'errors.user.user_not_found' => 'Пользователь не найден.',
    'errors.user.invalid_role' => 'Недопустимая роль.',
    'errors.user.no_changes' => 'Нет данных для обновления.',
];
