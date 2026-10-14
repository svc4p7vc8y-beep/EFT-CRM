// Keep task history in the same order people would read a conversation.
export function taskStory(task, activities = []) {
  const entries = [{ id: `task-${task.id}`, kind: 'assignment', at: task.createdAt || '', title: 'Задача поставлена', text: task.description?.trim() || task.title }];
  for (const activity of activities) {
    if (activity.taskId !== task.id) continue;
    if (activity.type === 'system' && activity.text === `Создано задание: ${task.title}`) continue;
    entries.push({ id: activity.id, kind: activity.subject === 'completion' ? 'completion' : activity.type === 'system' ? 'change' : 'comment', at: activity.createdAt || '', title: activity.subject === 'completion' ? 'Результат выполнения' : activity.type === 'system' ? 'Изменение задачи' : 'Комментарий', text: activity.text, authorId: activity.authorId });
  }
  if (task.question) entries.push({ id: `question-${task.id}`, kind: 'question', at: task.questionAskedAt || task.createdAt || '', title: 'Вопрос исполнителю', text: task.question, authorName: task.questionRequesterName });
  if (task.answer) entries.push({ id: `answer-${task.id}`, kind: 'answer', at: task.answeredAt || '', title: 'Ответ исполнителя', text: task.answer, authorName: task.answeredByName });
  return entries.sort((left, right) => left.at.localeCompare(right.at));
}
