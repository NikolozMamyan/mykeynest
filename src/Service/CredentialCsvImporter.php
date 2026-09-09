<?php

namespace App\Service;

use App\Entity\Credential;
use App\Entity\User;
use App\Repository\CredentialRepository;
use Psr\Log\LoggerInterface;

final class CredentialCsvImporter
{
    public const MAX_FILE_SIZE = 5 * 1024 * 1024;
    public const MAX_ROWS = 5000;

    /** @var array<string, list<string>> */
    private const HEADER_ALIASES = [
        'name' => ['name', 'title', 'label', 'nom'],
        'site' => ['domain', 'url', 'uri', 'website', 'login_uri', 'login_url', 'origin', 'hostname', 'domaine', 'site', 'adresse_web'],
        'username' => ['username', 'login_username', 'user', 'email', 'login', 'identifiant', 'utilisateur', 'nom_utilisateur', 'nom_d_utilisateur'],
        'password' => ['password', 'login_password', 'pass', 'mot_de_passe', 'motdepasse'],
    ];

    public function __construct(
        private readonly CredentialManager $credentialManager,
        private readonly CredentialRepository $credentialRepository,
        private readonly CredentialUrlPolicy $credentialUrls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     imported: int,
     *     skipped: int,
     *     failed: int,
     *     processed: int,
     *     items: list<array{line: int, status: string, key: string, parameters: array<string, scalar>}>,
     *     fatal: null|array{key: string, parameters: array<string, scalar>}
     * }
     */
    public function import(string $path, User $user, ?int $credentialLimit, int $existingCredentialCount): array
    {
        $result = [
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'processed' => 0,
            'items' => [],
            'fatal' => null,
        ];

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $result['fatal'] = ['key' => 'credential.import.errors.unreadable', 'parameters' => []];

            return $result;
        }

        try {
            $headerLine = $this->readHeaderLine($handle);
            if ($headerLine === null) {
                $result['fatal'] = ['key' => 'credential.import.errors.empty', 'parameters' => []];

                return $result;
            }

            [$headerText, $lineNumber, $separator] = $headerLine;
            $headers = str_getcsv($headerText, $separator, '"', '');
            $columnMap = $this->buildColumnMap($headers);
            $missing = array_values(array_filter(
                ['site', 'username', 'password'],
                static fn (string $column): bool => !isset($columnMap[$column]),
            ));

            if ($missing !== []) {
                $result['fatal'] = [
                    'key' => 'credential.import.errors.missing_columns',
                    'parameters' => ['%columns%' => implode(', ', $missing)],
                ];

                return $result;
            }

            /** @var array<string, true> $seen */
            $seen = [];
            foreach ($this->credentialRepository->findIdentityPairsByUser($user) as $identity) {
                $seen[$this->identityKey($identity['domain'], $identity['username'])] = true;
            }
            $rowCount = 0;

            while (($data = fgetcsv($handle, null, $separator, '"', '')) !== false) {
                ++$lineNumber;
                if ($this->isEmptyRow($data)) {
                    continue;
                }

                ++$rowCount;
                if ($rowCount > self::MAX_ROWS) {
                    ++$result['skipped'];
                    $result['items'][] = $this->item($lineNumber, 'warning', 'credential.import.results.too_many_rows', [
                        '%limit%' => self::MAX_ROWS,
                    ]);
                    break;
                }

                ++$result['processed'];
                if ($credentialLimit !== null && $existingCredentialCount + $result['imported'] >= $credentialLimit) {
                    ++$result['skipped'];
                    $result['items'][] = $this->item($lineNumber, 'warning', 'credential.import.results.limit_reached', [
                        '%limit%' => $credentialLimit,
                    ]);
                    break;
                }

                $name = $this->columnValue($data, $columnMap, 'name');
                $siteValue = $this->columnValue($data, $columnMap, 'site');
                $username = $this->columnValue($data, $columnMap, 'username');
                $password = $this->columnValue($data, $columnMap, 'password', false);

                if ($siteValue === '' || $username === '' || $password === '') {
                    ++$result['failed'];
                    $result['items'][] = $this->item($lineNumber, 'error', 'credential.import.results.missing_fields');
                    continue;
                }

                $site = $this->credentialUrls->parseSite($siteValue);
                if ($site === null) {
                    ++$result['failed'];
                    $result['items'][] = $this->item($lineNumber, 'error', 'credential.import.results.invalid_site', [
                        '%site%' => $siteValue,
                    ]);
                    continue;
                }

                $name = $name !== '' ? $name : $site['domain'];
                if (strlen($name) > 255 || strlen($site['domain']) > 255 || strlen($username) > 255) {
                    ++$result['failed'];
                    $result['items'][] = $this->item($lineNumber, 'error', 'credential.import.results.too_long');
                    continue;
                }

                $duplicateKey = $this->identityKey($site['domain'], $username);
                if (isset($seen[$duplicateKey])) {
                    ++$result['skipped'];
                    $result['items'][] = $this->item($lineNumber, 'warning', 'credential.import.results.duplicate', [
                        '%domain%' => $site['domain'],
                        '%username%' => $username,
                    ]);
                    continue;
                }

                try {
                    $credential = (new Credential())
                        ->setName($name)
                        ->setDomain($site['domain'])
                        ->setLoginUrl($site['loginUrl'])
                        ->setUsername($username)
                        ->setPassword($password);

                    $this->credentialManager->create($credential, $user);
                    $seen[$duplicateKey] = true;
                    ++$result['imported'];
                    $result['items'][] = $this->item($lineNumber, 'success', 'credential.import.results.imported', [
                        '%domain%' => $site['domain'],
                        '%username%' => $username,
                    ]);
                } catch (\Throwable $exception) {
                    ++$result['failed'];
                    $result['items'][] = $this->item($lineNumber, 'error', 'credential.import.results.write_error');
                    $this->logger->warning('Credential CSV row could not be imported.', [
                        'line' => $lineNumber,
                        'domain' => $site['domain'],
                        'exception' => $exception,
                    ]);
                }
            }
        } finally {
            fclose($handle);
        }

        return $result;
    }

    /** @param resource $handle
     *  @return array{string, int, string}|null
     */
    private function readHeaderLine($handle): ?array
    {
        $lineNumber = 0;
        $separator = null;

        while (($line = fgets($handle)) !== false) {
            ++$lineNumber;
            $line = $this->toUtf8(trim($line));
            if ($line === '') {
                continue;
            }

            if (preg_match('/^sep=(.)$/i', $line, $matches) === 1) {
                $separator = $matches[1];
                continue;
            }

            return [$line, $lineNumber, $separator ?? $this->detectSeparator($line)];
        }

        return null;
    }

    /** @param list<string|null> $headers
     *  @return array<string, int>
     */
    private function buildColumnMap(array $headers): array
    {
        $normalized = array_map(fn (?string $header): string => $this->normalizeHeader((string) $header), $headers);
        $map = [];

        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($alias, $normalized, true);
                if ($index !== false) {
                    $map[$canonical] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $this->toUtf8($header)) ?? ''));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $header), '_');
    }

    private function detectSeparator(string $line): string
    {
        $bestSeparator = ',';
        $bestCount = 0;

        foreach ([',', ';', "\t"] as $separator) {
            $count = count(str_getcsv($line, $separator, '"', ''));
            if ($count > $bestCount) {
                $bestSeparator = $separator;
                $bestCount = $count;
            }
        }

        return $bestSeparator;
    }

    /** @param list<string|null> $data
     *  @param array<string, int> $columnMap
     */
    private function columnValue(array $data, array $columnMap, string $column, bool $trim = true): string
    {
        if (!isset($columnMap[$column])) {
            return '';
        }

        $value = str_replace("\0", '', $this->toUtf8((string) ($data[$columnMap[$column]] ?? '')));

        return $trim ? trim($value) : $value;
    }

    /** @param list<string|null> $data */
    private function isEmptyRow(array $data): bool
    {
        foreach ($data as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function toUtf8(string $value): string
    {
        if ($value === '' || preg_match('//u', $value) === 1) {
            return $value;
        }

        return iconv('Windows-1252', 'UTF-8//IGNORE', $value) ?: $value;
    }

    private function identityKey(string $domain, string $username): string
    {
        return strtolower(trim($domain))."\0".strtolower(trim($username));
    }

    /** @param array<string, scalar> $parameters
     *  @return array{line: int, status: string, key: string, parameters: array<string, scalar>}
     */
    private function item(int $line, string $status, string $key, array $parameters = []): array
    {
        return compact('line', 'status', 'key', 'parameters');
    }
}
