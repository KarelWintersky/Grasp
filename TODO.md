# Database 

- id
- dt_download DATETIME
- dt_update DATETIME
- url
- path - путь к storage
- repo_size - размер скачанного репозитория
- userName - имя пользователя/организации
- repoName - имя репозитория
- updateInterval - интервал обновления в минутах
- repo_source - строка - github, gitflic, etc
- api_answer - TEXT - JSONized ответ сервера, например, от гитхаба
- status - текущий статус работы с репой:
  - новый, не скачан
  - скачивается
  - ждет обновления
  - обновляется
  - заморожен (не обновляется)
  - на удаление
  - удаляется
- интервал обновления (минут)

# Cron

Все задачи выполняют фоновые крон-скрипты

- скачивание 
- обновление 
- удаление 

# Public (web)

веб-морда, которая показывает таблицей отслеживаемые репозитории:

- дата последнего обновения
- repo_size
- путь к репозиторию на внешнем источнике
- статус задачи
- время до обновления
- описание репозитория
- команды
  - force update
  - freeze/unfreeze
  - delete
  - export

# NB

Экспорт репозитория из bare-хранилища в обычный вид:

```
git clone /путь/к/bare-репозиторию /путь/к/целевой/директории
```
