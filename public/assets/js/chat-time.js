function formatChatTime(value) {
  return dayjs(value).format(window.ChatConfig.timeFormat);
}

function formatChatDateTime(value) {
  return dayjs(value).format(window.ChatConfig.datetimeFormat);
}
