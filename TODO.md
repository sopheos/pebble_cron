# TODO — pebble_cron

Problèmes restant à traiter, détectés lors de l'audit du 2026-10-06. Le code `src/` n'a **pas** été modifié. Chaque bug est figé par un test qui vérifie le comportement actuel : il faut l'adapter au moment de la correction.

## Bugs

- [ ] **Paramètres implicitement nullables, dépréciés en PHP 8.4+.** `src/ScheduleChecker.php:17, 41`.
  - `DateTimeImmutable $now = null` et `string &$callable_name = null` émettent deux `E_DEPRECATED` à chaque chargement de la classe, donc à chaque `Cron::run()`. La lib exige PHP 8.5 : ces avertissements polluent les logs et deviendront une erreur dans une version future. PHPUnit les compte (« Deprecations: 2 »).
  - Correctif : `?DateTimeImmutable $now = null` et `?string &$callable_name = null`.
  - Test : `tests/ScheduleCheckerTest.php::testLoadingTheClassEmitsImplicitNullableDeprecations`.
- [ ] **`Cron::add()` copie `stdout` dans `stderr`.** `src/Cron.php:60`.
  - `$job->stderr($this->config['stdout'])` : la clé de config `stderr` est ignorée. Les erreurs des jobs partent dans le fichier de sortie standard (ou dans `/dev/null` si seul `stderr` est configuré).
  - Correctif : `$job->stderr($this->config['stderr']);`.
  - Test : `tests/CronTest.php::testAddCopiesStdoutIntoStderr`.
- [ ] **Seule la dernière commande d'une liste shell est redirigée.** `src/JobRunner.php:70`.
  - La commande est concaténée telle quelle avec `1>> "stdout" 2>> "stderr"`. Pour `a; b` ou `a && b`, seule `b` est redirigée. La sortie standard de `a` est avalée par le `$dummy` d'`exec()`, et son erreur part dans le `/dev/null` du processus parent.
  - Correctif : grouper la commande, `"{ $command\n} 1>> …"` ou `"($command) 1>> …"`.
  - Test : `tests/JobRunnerTest.php::testRedirectionOnlyAppliesToTheLastCommandOfAList`.
- [ ] **Les 5 essais de verrouillage durent environ 1 ms.** `src/Lock.php:51`.
  - `usleep(250)` attend 0,25 ms. L'intention était probablement 250 ms. La boucle de 5 essais abandonne donc en environ 1,25 ms et ne sert à rien.
  - Correctif : `usleep(250000)`.
  - Test : `tests/LockTest.php::testAcquireGivesUpAfterAboutOneMillisecond`.
- [ ] **`release()` supprime le fichier de verrou, ce qui permet deux détenteurs simultanés.** `src/Lock.php:69-71`.
  - Un processus qui a ouvert le fichier avant le `release()` verrouille ensuite l'inode supprimé, pendant qu'un nouveau processus crée un nouveau fichier et le verrouille aussi. Le cas est rare, car la fenêtre d'essai fait environ 1 ms, mais deux exécutions du même job peuvent alors se chevaucher.
  - Correctif : ne pas supprimer le fichier (le vider suffit), ou vérifier après `flock()` que l'inode du handle est toujours celui du chemin.
  - Test : `tests/LockTest.php::testReleaseUnlinksTheFileSoAStaleHandleAndANewLockBothSucceed`.

## Dette / qualité

- [ ] `src/JobRunner.php:103-116` : `getLogfile()` est du code mort. Elle n'est jamais appelée et lit des clés `output_stdout`/`output_stderr` qui ne sont jamais renseignées.
- [ ] `src/Job.php:49` : faute de frappe `minutly()` (pour `minutely()`). Ajouter un alias `minutely()` sans supprimer l'ancien nom, qui fait partie de l'API publique.
- [ ] `src/JobRunner.php:44-46` : un échec d'acquisition du verrou (job encore en cours) n'est pas journalisé. Le run est sauté sans trace.
- [ ] `src/JobRunner.php:33-39` : au-delà de `max_runtime`, le job en cours n'est pas tué. Un avertissement est ajouté au log à chaque minute, rien de plus.
- [ ] `src/Lock.php:37-55` : le handle ouvert n'est pas fermé quand les 5 essais échouent.
- [ ] `src/Helper.php:13-22` : `escape()` peut renvoyer une chaîne vide (`'!!!'`, noms non ASCII), et les fichiers deviennent alors `<tmpdir>/.lock`, `.json`, `.crash`. Deux noms qui ne diffèrent que par la casse ou la ponctuation partagent les mêmes fichiers.
- [ ] `src/Cron.php:77-80` : `cron.json` est écrit à la racine de `tmpdir` sans préfixe `app`. Deux applications qui partagent le même `tmpdir` l'écrasent mutuellement.
- [ ] `src/Cron.php:36-38` : un `tmpdir` inexistant retombe silencieusement sur `sys_get_temp_dir()`.
- [ ] `src/Cron.php:90` : `$binary` n'est pas échappé (un chemin avec espace casse la commande), et `escapeshellarg()` serait plus sûr que les guillemets doubles de `getExecutableCommand()`. `symfony/process` n'est utilisé que pour `PhpExecutableFinder`, alors qu'il pourrait lancer le processus.
- [ ] `src/ScheduleChecker.php:41-57` : un callable tableau non statique déclenche un `E_USER_DEPRECATED` en français approximatif (« ne pas être appellé »), puis tombe sur `DateTime::createFromFormat()` et lève un `TypeError`.
- [ ] `src/InfoException.php` : jamais levée, alors que `Lock::acquire()` la déclare dans son `@throws`.
- [ ] Docblocks faux : `Cron::add()` documente `$command` et `$schedule`, et `Job::getSchedule()` documente un `$name` inexistant.
