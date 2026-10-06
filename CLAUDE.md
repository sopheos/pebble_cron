# CLAUDE.md — pebble_cron

Ce fichier guide Claude Code quand il **maintient** cette librairie. Pour l'**utiliser** depuis un projet, voir le skill [`skills/pebble-cron/`](skills/pebble-cron/SKILL.md).

## Rôle

`sopheos/pebble_cron`, namespace `Pebble\Cron\`, PHP >= 8.5, extension `posix`. Dépendances : `dragonmantank/cron-expression` (évaluation des expressions) et `symfony/process` (uniquement `PhpExecutableFinder`, pour trouver le binaire PHP).

La lib est un ordonnanceur appelé chaque minute par le crontab système :
- `Cron` déclare les jobs, écrit `cron.json` et lance chaque job dû en arrière-plan avec `exec("php src/run-job.php '<query string>' &")` ;
- `run-job.php` reconstruit la config et appelle `JobRunner`, qui gère les fichiers `<nom>.lock`, `<nom>.crash`, `<nom>.disabled` et le log `<nom>.json` dans `tmpdir`, puis exécute la commande shell.

Pas de file d'attente, pas de kill des jobs trop longs, pas de reprise automatique après un crash.

## Commandes

```bash
composer install
vendor/bin/phpunit            # toute la suite
vendor/bin/phpunit --filter JobRunnerTest
```

## Carte de `src/`

| Fichier | Rôle |
|---|---|
| `Cron.php` | Config (`app`, `max_runtime`, `stdout`, `stderr`, `tmpdir`), `add()` crée un `Job`, `run()` écrit `cron.json` et lance les jobs dus en arrière-plan |
| `Job.php` | Définition d'un job (nom, commande, planning, max runtime, sorties), raccourcis `every()`/`minutly()`/`hourly()`/`daily()`, `export()` |
| `ScheduleChecker.php` | `isDue()` : callable, date `Y-m-d H:i:s` (à la minute) ou expression cron |
| `run-job.php` | Script lancé en arrière-plan : `parse_str($argv[1])` puis `JobRunner::run()` |
| `JobRunner.php` | Fichiers crash/disabled, contrôle du max runtime, verrou, exécution, log JSON (100 dernières entrées) |
| `Lock.php` | Verrou `flock` non bloquant (5 essais), PID écrit dans le fichier, `getLifetime()` via `posix_kill` |
| `Helper.php` | `escape()` (nom de fichier), `getPhpBinary()`, `getTempDir()` |
| `Exception.php` / `InfoException.php` | Exceptions de la lib. `InfoException` n'est jamais levée |

## Tests

- PHPUnit 13 (`require-dev` en `^13`, cohérent avec PHP >= 8.5, au lieu du `^9.5` des autres libs). Les data providers passent par l'attribut `#[DataProvider]`.
- Les classes de test n'ont pas de namespace. Les méthodes s'appellent `testPhraseEnCamelCase`, les assertions passent par `self::assertSame`, et des bannières `// ----` séparent les sections.
- Chaque test travaille dans un dossier `sys_get_temp_dir()/pebble_cron_*` qu'il supprime dans `tearDown()`.
- `JobRunnerTest` exécute de vraies commandes shell inoffensives (`true`, `touch`, `echo`). `CronTest` lance un vrai job en arrière-plan et attend son log (quelques dizaines de ms).
- La suite affiche « Deprecations: 2 » : c'est le bug des paramètres implicitement nullables de `ScheduleChecker` (voir `TODO.md`), pas une erreur des tests.

## Conventions du code

Respecter le style existant, sans le « moderniser » au passage :
- pas de `declare(strict_types=1)` ;
- propriétés typées dans `Job`, `JobRunner` et `Lock`, mais docblocks `@var` dans `Cron` et `ScheduleChecker` ;
- docblocks `@return static` ou `@param` même quand la signature est typée ;
- messages de log en français (`Fini en …s`), messages d'exception en anglais.

Une modification de comportement doit être répercutée dans `skills/pebble-cron/` (SKILL.md, `references/api-reference.md`, `references/gotchas.md`) et dans le `README.md`.

## Bugs connus

Ils sont listés dans [`TODO.md`](TODO.md). Chacun est **figé par un test** annoté `// BUG:` qui vérifie le comportement *actuel*, dans la section « Known bugs » du fichier de test de la classe concernée.

Pour corriger un bug :
1. Corriger `src/`.
2. Réécrire le test `// BUG:` pour qu'il vérifie le comportement attendu.
3. Mettre à jour l'entrée « (bug) » de `skills/pebble-cron/references/gotchas.md` et le SKILL.md.
4. Retirer l'entrée de `TODO.md` (il ne liste que ce qui reste à faire).
