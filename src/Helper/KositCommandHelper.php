<?php
/*
 * Created on   : Tue Jun 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : KositCommandHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Helper;

use CommonToolkit\Contracts\Abstracts\ConfiguredHelperAbstract;
use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;

/**
 * Zugriff auf die konfigurierte KoSIT-Validator-Executable.
 *
 * Der Validator ist als `javaExecutables`-Eintrag in
 * config/erechnung_executables.json hinterlegt und kann dort (Jar-Pfad,
 * Argumente) auf ein eigenes Jar umgestellt werden.
 *
 * - Die Java-Laufzeit wird NICHT dupliziert: ihre Verfügbarkeit kommt aus dem
 *   Common-Toolkit ({@see self::isExecutableAvailable()} auf "java").
 * - Jar-Pfad und Argumente werden direkt aus der Config-JSON gelesen, da das
 *   gebündelte Jar paket-relativ liegt und nicht über die (arbeitsverzeichnis-
 *   abhängige) PATH-Auflösung des ConfigLoader aufgelöst werden kann.
 */
final class KositCommandHelper extends ConfiguredHelperAbstract {
    protected const CONFIG_FILE = __DIR__ . '/../../config/erechnung_executables.json';

    private const VALIDATOR = 'kosit-validator';

    private static bool $commonRuntimeLoaded = false;

    /** @var array<string, mixed>|null */
    private static ?array $rawConfig = null;

    /**
     * Prüft, ob die Java-Laufzeit verfügbar ist (PATH-Auflösung via Konfiguration).
     *
     * Der "java"-Eintrag stammt aus dem Common-Toolkit; dessen Executable-Config
     * wird dafür in den prozessweit geteilten ConfigLoader geladen.
     */
    public static function isJavaAvailable(): bool {
        self::ensureCommonRuntimeLoaded();
        return self::isExecutableAvailable('java');
    }

    /**
     * Liefert den konfigurierten Jar-Pfad (absolut). Relative Pfade werden gegen
     * das Paket-Wurzelverzeichnis aufgelöst, sodass das gebündelte
     * tools/kosit/validator.jar unabhängig vom Arbeitsverzeichnis gefunden wird.
     */
    public static function configuredJarPath(): ?string {
        $path = self::validatorConfig()['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return null;
        }
        if (!self::isAbsolutePath($path)) {
            $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $path;
        }
        return File::exists($path) ? $path : null;
    }

    /**
     * Liefert die konfigurierten Validator-Argumente mit ersetzten Platzhaltern.
     *
     * @param array<string, string> $replacements z.B. ['[REPOSITORY]' => '/pfad', ...]
     * @return string[]
     */
    public static function validatorArguments(array $replacements): array {
        $arguments = self::validatorConfig()['arguments'] ?? [];
        if (!is_array($arguments)) {
            return [];
        }
        return array_values(array_map(
            static fn ($arg): string => is_string($arg) ? strtr($arg, $replacements) : (string) $arg,
            $arguments
        ));
    }

    /**
     * Lädt die Executable-Konfiguration des Common-Toolkit (inkl. "java") einmalig
     * in den geteilten ConfigLoader, damit kein eigener java-Eintrag nötig ist.
     */
    private static function ensureCommonRuntimeLoaded(): void {
        if (!self::$commonRuntimeLoaded) {
            Shell::getConfiguredExecutables();
            self::$commonRuntimeLoaded = true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function validatorConfig(): array {
        if (self::$rawConfig === null) {
            $decoded = json_decode(File::read(static::CONFIG_FILE), true);
            self::$rawConfig = is_array($decoded) ? $decoded : [];
        }
        $entry = self::$rawConfig['javaExecutables'][self::VALIDATOR] ?? [];
        return is_array($entry) ? $entry : [];
    }

    private static function isAbsolutePath(string $path): bool {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
