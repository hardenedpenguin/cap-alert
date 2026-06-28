<?php

declare(strict_types=1);

namespace CapAlert;

/** CPU temperature and health flags via Linux thermal/hwmon sysfs, with Pi firmware fallback. */
final class HostHardware
{
    private const THROTTLED_SYSFS = '/sys/devices/platform/soc/soc:firmware/get_throttled';

    /** @var non-empty-string */
    private const TEMP_ZONE_PATTERN = '/cpu|soc|bcm|xgene|pkg|acpitz|acpi|pch|k10temp|coretemp/i';

    /** @var non-empty-string */
    private const HWMON_NAME_PATTERN = '/coretemp|k10temp|xgene|cpu_thermal|soc_thermal|acpitz|pch/i';

    public static function readCpuTemperatureCelsius(): ?float
    {
        $best = self::readBestThermalZoneTemp();
        if ($best !== null) {
            return $best;
        }

        $hwmon = self::readBestHwmonTemp();
        if ($hwmon !== null) {
            return $hwmon;
        }

        if (self::isRaspberryPi()) {
            return self::readTemperatureVcgencmd();
        }

        return null;
    }

    /** @return non-empty-string */
    public static function readLiveHealthFlags(): string
    {
        $flags = [];

        $piFlags = self::readRaspberryPiLiveThrottleFlags();
        if ($piFlags !== '') {
            $flags = array_merge($flags, explode('_ ', $piFlags));
        }

        if (self::isThermalCoolingActive()) {
            $flags[] = 'currently-throttled';
        }

        foreach (self::readHwmonCriticalAlarmFlags() as $flag) {
            $flags[] = $flag;
        }

        $flags = array_values(array_unique(array_filter($flags)));

        return implode('_ ', $flags);
    }

    public static function parseThrottledHex(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '0x') || str_starts_with($raw, '0X')) {
            $raw = substr($raw, 2);
        }

        if ($raw === '' || !ctype_xdigit($raw)) {
            return null;
        }

        return (int) hexdec($raw);
    }

    /** @return non-empty-string */
    public static function formatLiveThrottleFlags(int $value): string
    {
        $flags = [];
        if ($value & 0x00001) {
            $flags[] = 'under-voltage-detected';
        }
        if ($value & 0x00002) {
            $flags[] = 'arm-frequency-capped';
        }
        if ($value & 0x00004) {
            $flags[] = 'currently-throttled';
        }
        if ($value & 0x00008) {
            $flags[] = 'soft-temp-limit-active';
        }

        return implode('_ ', $flags);
    }

    private static function readBestThermalZoneTemp(): ?float
    {
        $fallback = null;

        foreach (glob('/sys/class/thermal/thermal_zone*') ?: [] as $zone) {
            $type = self::readThermalZoneType($zone);
            $temp = self::readMillidegrees("$zone/temp");
            if ($temp === null) {
                continue;
            }

            if ($type !== null && preg_match(self::TEMP_ZONE_PATTERN, $type)) {
                return $temp;
            }

            if ($fallback === null) {
                $fallback = $temp;
            }
        }

        return $fallback ?? self::readMillidegrees('/sys/class/thermal/thermal_zone0/temp');
    }

    private static function readThermalZoneType(string $zone): ?string
    {
        $typeFile = "$zone/type";
        if (!is_readable($typeFile)) {
            return null;
        }

        $type = trim((string) file_get_contents($typeFile));

        return $type !== '' ? $type : null;
    }

    private static function readBestHwmonTemp(): ?float
    {
        $fallback = null;

        foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $hwmon) {
            $name = self::readHwmonName($hwmon);
            $preferred = $name !== null && preg_match(self::HWMON_NAME_PATTERN, $name);

            foreach (glob("$hwmon/temp*_input") ?: [] as $path) {
                $temp = self::readMillidegrees($path);
                if ($temp === null) {
                    continue;
                }

                if ($preferred) {
                    return $temp;
                }

                if ($fallback === null) {
                    $fallback = $temp;
                }
            }
        }

        return $fallback;
    }

    private static function readHwmonName(string $hwmon): ?string
    {
        $nameFile = "$hwmon/name";
        if (!is_readable($nameFile)) {
            return null;
        }

        $name = trim((string) file_get_contents($nameFile));

        return $name !== '' ? $name : null;
    }

    /** @return list<string> */
    private static function readHwmonCriticalAlarmFlags(): array
    {
        $flags = [];

        foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $hwmon) {
            foreach (glob("$hwmon/temp*_critical_alarm") ?: [] as $alarmFile) {
                if (!is_readable($alarmFile)) {
                    continue;
                }
                if ((int) trim((string) file_get_contents($alarmFile)) === 1) {
                    $flags[] = 'soft-temp-limit-active';
                }
            }
        }

        return $flags;
    }

    private static function isThermalCoolingActive(): bool
    {
        foreach (glob('/sys/class/thermal/cooling_device*') ?: [] as $device) {
            $stateFile = "$device/cur_state";
            if (!is_readable($stateFile)) {
                continue;
            }
            if ((int) trim((string) file_get_contents($stateFile)) > 0) {
                return true;
            }
        }

        return false;
    }

    /** @return non-empty-string */
    private static function readRaspberryPiLiveThrottleFlags(): string
    {
        if (!self::isRaspberryPi()) {
            return '';
        }

        $value = self::readThrottledValue();
        if ($value === null) {
            return '';
        }

        return self::formatLiveThrottleFlags($value);
    }

    private static function isRaspberryPi(): bool
    {
        if (!is_readable('/proc/device-tree/model')) {
            return false;
        }

        $model = strtolower(trim((string) file_get_contents('/proc/device-tree/model')));

        return str_contains($model, 'raspberry pi');
    }

    private static function readMillidegrees(string $path): ?float
    {
        if (!is_readable($path)) {
            return null;
        }

        $raw = trim((string) file_get_contents($path));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return ((float) $raw) / 1000;
    }

    private static function readThrottledValue(): ?int
    {
        foreach (self::throttledSysfsPaths() as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $value = self::parseThrottledHex((string) file_get_contents($path));
            if ($value !== null) {
                return $value;
            }
        }

        return self::readThrottledVcgencmd();
    }

    /** @return list<string> */
    private static function throttledSysfsPaths(): array
    {
        $paths = [self::THROTTLED_SYSFS];
        foreach (glob('/sys/devices/platform/*/soc:firmware/get_throttled') ?: [] as $path) {
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    private static function readTemperatureVcgencmd(): ?float
    {
        $line = self::runVcgencmd('measure_temp');
        if ($line === '') {
            return null;
        }

        $line = trim(str_replace(["temp=", "'", 'C'], '', $line));
        if (!is_numeric($line)) {
            return null;
        }

        return (float) $line;
    }

    private static function readThrottledVcgencmd(): ?int
    {
        $line = self::runVcgencmd('get_throttled');
        if (!preg_match('/^throttled=0[xX]([0-9A-Fa-f]+)$/', $line, $match)) {
            return null;
        }

        return (int) hexdec($match[1]);
    }

    private static function runVcgencmd(string $args): string
    {
        foreach (['/usr/bin/vcgencmd', 'vcgencmd'] as $bin) {
            if (!Shell::commandExists($bin)) {
                continue;
            }
            $line = trim((string) shell_exec(escapeshellarg($bin) . ' ' . escapeshellarg($args) . ' 2>/dev/null'));
            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }
}
