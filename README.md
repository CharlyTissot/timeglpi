# Plugin GLPI BETA— Gestion du temps (`gestiontemps`)
> ☕ **Un café pour soutenir mes projets libres ?!** → https://buymeacoffee.com/charlytissot

[![Buy Me A Coffee](https://img.shields.io/badge/%E2%98%95%20Un%20caf%C3%A9%20pour%20soutenir%20mes%20projets%20libres-FFDD00?style=for-the-badge&logo=buymeacoffee&logoColor=black)](https://buymeacoffee.com/charlytissot)


Plugin de gestion du temps pour **GLPI 10 (≥ 10.0.6) et GLPI 11**.

## Fonctionnalités

- **Comptabilisation du temps des tickets** = *production*. Le temps saisi dans
  les **tâches de ticket** GLPI est automatiquement repris (via hooks) comme
  temps de production, sans double saisie. Un **minuteur** dédié est aussi
  disponible.
- **Saisie manuelle de temps** = *non-production* (temps non rattaché à un ticket).
- **Vue % production** + **disque « poids lourd »** (donut style tachygraphe, SVG,
  sans dépendance externe).
- **Tirelire (compte de temps)** : les **heures supplémentaires** (temps réel
  au-delà de l'horaire théorique) alimentent une tirelire par utilisateur. Le
  **service RH** (profils GLPI choisis en configuration) peut **décréditer** la
  tirelire lors d'une prise.
- **Horaires théoriques** par utilisateur, avec **alternance semaine A / semaine B**.
- **Journal d'audit** : chaque action (saisie, crédit/débit, config, horaires)
  est tracée « qui / quoi / quand ».

## Installation

1. Copier le dossier dans `glpi/plugins/gestiontemps/`
   (le nom du dossier **doit** être `gestiontemps`).
2. Dans GLPI : **Configuration → Plugins** → *Installer* puis *Activer*.
3. Ouvrir la roue crantée du plugin (**Configuration**) pour :
   - sélectionner les **profils RH** autorisés à décréditer la tirelire ;
   - régler le **quota hebdomadaire** de référence et la **parité semaine A/B**.
4. Attribuer les droits aux profils : **Administration → Profils → onglet
   « Gestion du temps »**.
5. Renseigner les **horaires** de chaque utilisateur : **Administration →
   Utilisateurs → onglet « Horaires »** (grille normale + semaine A + semaine B).

## Utilisation

- **Tableau de bord** : Outils → Gestion du temps → Tableau de bord
  (% production + disque, filtrables par période et par utilisateur).
- **Saisie manuelle** : Outils → Gestion du temps → Saisies de temps → *Ajouter*.
  Un temps **sans ticket** est du non-production ; **avec ticket**, de la production.
- **Tirelire** : Outils → Gestion du temps → Tirelire. Les membres RH peuvent
  choisir un utilisateur et décréditer.
- **Heures supplémentaires** : calculées automatiquement chaque nuit par la tâche
  **cron** `computeOvertime` (Configuration → Actions automatiques). Elle crédite,
  pour la semaine ISO écoulée, l'écart *temps réel − horaire théorique*.
- **Journal** : Outils → Gestion du temps → Journal (recherche filtrable).

## Architecture

```
setup.php / hook.php          amorçage, install/uninstall, hooks tâches, cron
src/                          classes PSR-4  (GlpiPlugin\Gestiontemps\*)
  Config, Profile, Journal, Menu
  TimeEntry                   saisies (production/manuel) + hooks TicketTask
  Schedule                    horaires théoriques (semaine A/B)
  Account, AccountMove        tirelire + mouvements + cron heures supp
  Dashboard/ProductionCard    agrégats du tableau de bord
  Toolbox/Compat, Toolbox/Time  compat 10/11 + helpers durée
front/                        pages (dashboard, timeentry, account, schedule, journal, config)
ajax/timer.php                minuteur start/stop
css/ js/                      disque SVG + styles
```

### Compatibilité 10/11

- Autoloading **PSR-4** (`src/`, namespace `GlpiPlugin\Gestiontemps\`), pris en
  charge par GLPI 10.0.6+ et 11.
- `src/Toolbox/Compat.php` isole les rares différences d'API entre versions.
- Aucune dépendance au nouveau routeur Symfony de GLPI 11 : les pages restent des
  contrôleurs `front/*.php` classiques.

## Vérification (sur votre instance)

Après installation :

```sql
SHOW TABLES LIKE 'glpi_plugin_gestiontemps_%';
-- attendu : configs, timeentries, schedules, accounts, accountmoves, journals
```

1. Créer une **tâche** avec durée sur un ticket → une saisie **production**
   apparaît (onglet « Saisies de temps » du ticket).
2. Ajouter une **saisie manuelle** sans ticket → **non-production**.
3. Ouvrir le **tableau de bord** → le **% production** et le **disque** reflètent
   les deux.
4. Configurer un horaire (semaine A/B), lancer la tâche cron `computeOvertime`
   (Actions automatiques → *Exécuter*) → la **tirelire** est créditée.
5. En tant que **RH**, décréditer → le solde diminue et une ligne apparaît dans
   le **journal**.
6. Vérifier qu'un utilisateur **non-RH** ne voit **pas** le bouton de débit.

## Licence

GPL-3.0-or-later — Proximiweb.
