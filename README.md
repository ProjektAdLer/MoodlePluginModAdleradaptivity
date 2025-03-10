# About this project

Dieses Moodle Modul bildet das Adaptivitätselement aus der 3D-Umgebung in Moodle ab.
In Moodle wird keine Adaptivität unterstützt, es werden lediglich alle Fragen und Informationen angezeigt
und können vom Nutzer auch bearbeitet werden. Der Zustand zwischen 3D und Moodle ist dabei identisch.

[![Coverage Status](https://coveralls.io/repos/github/ProjektAdLer/MoodlePluginModAdleradaptivity/badge.svg?branch=main)](https://coveralls.io/github/ProjektAdLer/MoodlePluginModAdleradaptivity?branch=main)
> [!NOTE]  
> Der Coverage-Wert bildet nur die Coverage von PHPUnit ab. Behat Tests sind nicht enthalten.



![database diagram](db_diagram.png)

## Dependencies
> [!CAUTION]
> Dieses Plugin kann nur in Verwendung mit dem gesamten AdLer Projekt verwendet werden. Es funktioniert zwar theoretisch standalone,
> es ist aber nicht vorgesehen, Elemente über Moodle anzulegen/zu bearbeiten, wodurch es für sich alleine nicht sinnvoll nutzbar ist. 

## Kompabilität
Folgende Versionen werden unterstützt (mit mariadb und postresql getestet):

siehe [plugin_compatibility.json](plugin_compatibility.json)

## Development
Um die question bank für ein Adaptivitätsmodul anzuzeigen, den folgenden Code in `/lib.php` einfügen:
```php
function adleradaptivity_extend_settings_navigation(settings_navigation $settings, navigation_node $adleradaptivity_node) {
    global $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    question_extend_settings_navigation($adleradaptivity_node, $settings->get_page()->cm->context);
}
```
Dieser ist regulär nicht enthalten, da das Bearbeiten der Fragen in der Fragen in AdLer über Moodle nicht erlaubt ist.

## Potential future improvements
- [ ] Trigger event when a task is successfully completed (also backup/restore logic). ATM the only purpose of this event would be an entry in the audit log.
