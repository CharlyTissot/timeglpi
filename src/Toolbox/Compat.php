<?php

namespace GlpiPlugin\Gestiontemps\Toolbox;

/**
 * Couche de compatibilité GLPI 10 / 11.
 *
 * Isole les rares divergences d'API entre les deux versions afin que le
 * reste du plugin s'appuie uniquement sur l'API stable commune.
 */
class Compat
{
    /**
     * Retourne la version courante de GLPI (ex. "10.0.14" ou "11.0.0").
     */
    public static function glpiVersion(): string
    {
        return defined('GLPI_VERSION') ? GLPI_VERSION : '0';
    }

    /**
     * Teste si la version de GLPI est au moins celle fournie.
     */
    public static function glpiVersionAtLeast(string $version): bool
    {
        return version_compare(self::glpiVersion(), $version, '>=');
    }

    /**
     * Vrai si l'on tourne sur GLPI 11 ou supérieur.
     */
    public static function isGlpi11(): bool
    {
        return self::glpiVersionAtLeast('11.0.0');
    }

    /**
     * Message d'erreur d'installation formaté (compatible 10 et 11).
     */
    public static function checkMessage(string $message): string
    {
        return "<p class='red'>" . htmlspecialchars($message) . "</p>";
    }

    /**
     * Chemin web de base du plugin (pour les liens front / assets).
     */
    public static function webPath(): string
    {
        if (class_exists('\Plugin') && method_exists('\Plugin', 'getWebDir')) {
            return \Plugin::getWebDir('gestiontemps');
        }
        return (defined('GLPI_ROOT') ? '' : '') . '/plugins/gestiontemps';
    }

    /**
     * Rendu d'un template Twig du plugin.
     *
     * Le moteur Twig est disponible en GLPI 10.0.6+ et 11 via
     * TemplateRenderer. On repli sur une inclusion PHP si indisponible.
     *
     * @param array<string,mixed> $params
     */
    public static function renderTwig(string $template, array $params = []): void
    {
        if (class_exists('\Glpi\Application\View\TemplateRenderer')) {
            \Glpi\Application\View\TemplateRenderer::getInstance()->display(
                '@gestiontemps/' . $template,
                $params
            );
            return;
        }
        // Repli minimal : rien (les pages front fournissent un rendu PHP direct).
    }

    /**
     * Horodatage courant tel qu'utilisé par GLPI pour les champs date.
     */
    public static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }
}
