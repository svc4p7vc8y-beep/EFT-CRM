const byTime = (left, right) => (left.at || '').localeCompare(right.at || '');

export function taskStory(task, activities = []) {
  const entries = [];
  for (const activity of activities) {
    if (activity.taskId !== task.id || (activity.type === 'system' && activity.subject !== 'checklist.done')) continue;
    if (activity.subject === 'checklist.added' || activity.subject === 'checklist.reopened') continue;
    const kind = activity.subject === 'completion' ? 'completion' : activity.subject === 'checklist.done' ? 'check' : 'comment';
    entries.push({ id: activity.id, kind, at: activity.createdAt || '', title: kind === 'completion' ? 'Результат выполнения' : kind === 'check' ? 'Пункт выполнен' : 'Комментарий', text: activity.text, authorId: activity.authorId, authorName: activity.authorName });
  }
  if (task.question) entries.push({ id: `question-${task.id}`, kind: 'question', at: task.questionAskedAt || task.createdAt || '', title: 'Вопрос исполнителю', text: task.question, authorName: task.questionRequesterName });
  if (task.answer) entries.push({ id: `answer-${task.id}`, kind: 'answer', at: task.answeredAt || '', title: 'Ответ исполнителя', text: task.answer, authorName: task.answeredByName });
  return entries.sort(byTime);
}

export function taskHistory(task, activities = []) {
  const entries = [{ id: `task-${task.id}`, at: task.createdAt || '', text: 'Задача создана', authorName: task.createdByName || '' }];
  for (const activity of activities) {
    if (activity.taskId !== task.id || activity.subject === 'completion' || activity.subject === 'checklist.done') continue;
    if (activity.type !== 'system' && !['checklist.added', 'checklist.reopened'].includes(activity.subject)) continue;
    if (activity.type === 'system' && activity.text === `Создано задание: ${task.title}`) continue;
    entries.push({ id: activity.id, at: activity.createdAt || '', text: activity.text, authorId: activity.authorId, authorName: activity.authorName });
  }
  return entries.sort(byTime).reverse();
}
