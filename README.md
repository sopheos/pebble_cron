# Pebble/Cron

Ordonnanceur de tâches pour PHP 8.5+ : un seul point d'entrée appelé chaque minute par le crontab système lance chaque job dû dans un processus PHP en arrière-plan, avec un fichier de verrou, un fichier de crash et un log JSON par job.

La lib ne tue pas les jobs trop longs et ne relance pas un job qui a planté : il faut supprimer son fichier `.crash` à la main.

## Installation

```bash
composer require sopheos/pebble_cron
```

L'extension `posix` est requise.

## Claude Code

Ce package fournit un skill Claude Code dans [`skills/pebble-cron/`](skills/pebble-cron/). Il documente les patterns d'usage et les pièges de la librairie : fichier `.crash` qui bloque le job jusqu'à suppression manuelle, `stderr` de la config ignoré, redirection limitée à la dernière commande d'une liste shell, `max_runtime` qui ne tue rien, etc.

Dans un projet qui dépend de `sopheos/pebble_cron`, copie-le une fois dans `.claude/skills/` après `composer install` pour que Claude Code le charge automatiquement. Le nom du dossier doit correspondre au `name` déclaré dans `SKILL.md` :

```bash
cp -r vendor/sopheos/pebble_cron/skills/pebble-cron .claude/skills/pebble-cron
```

Pour la maintenance de la lib elle-même, voir [`CLAUDE.md`](CLAUDE.md). Les bugs connus sont listés dans [`TODO.md`](TODO.md).

## Mise en place

```php
// bin/cron.php, appelé par : * * * * * php /var/www/app/bin/cron.php
require __DIR__ . '/../vendor/autoload.php';

$cron = new \Pebble\Cron\Cron([
    'app' => 'shop',
    'tmpdir' => '/var/www/app/var/cron',
    'stdout' => '/var/www/app/var/log/cron.log',
]);

$cron->add('backup')->command('php /var/www/app/bin/backup.php')->daily(3);
$cron->add('mails')->command('php /var/www/app/bin/mails.php')->every(minute: '*/5');

$cron->run();
```

## Cron

`\Pebble\Cron\Cron` déclare les jobs et lance ceux qui sont dus.

* `__construct(array $config = [])` Clés : `app` (préfixe des noms, `null`), `max_runtime` (`300` s), `stdout` et `stderr` (`/dev/null`), `tmpdir` (dossier des fichiers d'état ; s'il est absent ou n'existe pas, `sys_get_temp_dir()`).
* `add(string $name) : Job` Crée un job nommé `<app>_<name>` et lui applique `max_runtime` et `stdout`. **Attention** : la clé `stderr` est ignorée, le job reçoit `stdout` à la place (voir [`TODO.md`](TODO.md)).
* `run()` Lève `Exception` sans l'extension `posix`. Écrit la liste des jobs dans `<tmpdir>/cron.json`, puis lance chaque job dû avec `php src/run-job.php` en arrière-plan. La méthode rend la main immédiatement.

## Job

`\Pebble\Cron\Job` décrit un job. Tous les setters sont chaînables.

* `command(string $command) : static` Commande shell (`pwd` par défaut).
* `schedule(string $schedule) : static` Expression cron (`* * * * *` par défaut), ou date `Y-m-d H:i:s` pour un job ponctuel (comparée à la minute). Non validée ici.
* `every($minute = "*", $hour = "*", $dayMonth = "*", $month = "*", $dayWeek = "*") : static` Construit l'expression cron. Les arguments nommés sont pratiques : `every(minute: '*/5')`.
* `minutly() : static` Chaque minute (le nom comporte une faute de frappe).
* `hourly() : static` Minute 0 de chaque heure.
* `daily(int $hour) : static` Tous les jours à `$hour:00`.
* `maxRuntime(int $max) : static` Durée en secondes au-delà de laquelle un run encore en cours produit un avertissement.
* `stdout(string $out) : static` / `stderr(string $out) : static` Fichiers où la sortie est **ajoutée** (`>>`).
* `getSchedule() : string` Planning courant.
* `export() : array` `name`, `command`, `schedule`, `max_runtime`, `stdout`, `stderr`. Un nom vide est remplacé par le `md5` de la commande. `jsonSerialize()` renvoie la même chose.

## JobRunner

`\Pebble\Cron\JobRunner` exécute un job dans le processus lancé par `run-job.php`. Les fichiers d'état s'appellent `<tmpdir>/<nom échappé>.*`, où le nom passe par `Helper::escape()` (minuscules, `[a-z0-9_.-]`, espaces en `_`).

* `__construct(array $config)` La config est celle de `Job::export()` plus `tmpdir`.
* `run()` Dans l'ordre :
  1. si `<nom>.crash` ou `<nom>.disabled` existe, ne fait rien ;
  2. si le verrou est tenu par un processus vivant depuis plus de `max_runtime` secondes, ajoute un log `Warning` et s'arrête, sans tuer ce processus ;
  3. si le verrou ne peut pas être pris (job encore en cours), s'arrête sans log ;
  4. exécute `<commande> 1>> "stdout" 2>> "stderr"`. Un code de retour non nul crée `<nom>.crash` et un log `Error`, sinon un log `Info` (`Fini en Ns`) est ajouté.

Le log `<nom>.json` contient les 100 dernières entrées `{ref, date, status, message}`. Un job planté ne tourne plus jusqu'à la suppression de son fichier `.crash`. Pour suspendre un job, créer `<nom>.disabled`.

## Lock

`\Pebble\Cron\Lock` est un verrou `flock` sur un fichier.

* `acquire() : true` Écrit le PID dans le fichier. Lève `Exception` si le verrou est déjà pris par cette instance, ou après 5 essais non bloquants (environ 1 ms en tout).
* `release()` Libère le verrou et supprime le fichier.
* `getLifetime() : int` Âge en secondes du fichier si le PID qu'il contient est vivant (`posix_kill`), sinon `0`.

## ScheduleChecker

`\Pebble\Cron\ScheduleChecker` dit si un planning est dû.

* `__construct(DateTimeImmutable $now = null)` Instant de référence, maintenant par défaut.
* `isDue($schedule) : bool` Un callable reçoit `$now` et renvoie le résultat. Une date `Y-m-d H:i:s` est comparée à la minute près. Sinon, l'expression cron est évaluée par `dragonmantank/cron-expression` (`InvalidArgumentException` si elle est invalide).

## Helper

* `escape($input) : string` Nom de fichier : minuscules, caractères hors `[a-z0-9_. -]` supprimés, espaces en `_`. Peut renvoyer une chaîne vide.
* `getPhpBinary() : string` Binaire PHP trouvé par `symfony/process`.
* `getTempDir() : string` `sys_get_temp_dir()`.

## Tests

```bash
composer install
vendor/bin/phpunit
```

Les tests utilisent un dossier temporaire qu'ils suppriment, et lancent de vraies commandes inoffensives. Les bugs connus sont figés par des tests annotés `// BUG:` qui vérifient le comportement actuel. Les 2 dépréciations affichées par PHPUnit viennent de `ScheduleChecker` (voir [`TODO.md`](TODO.md)).
