<?php
declare(strict_types=1);

namespace Diwan\Download;

use Diwan\Config\Env;
use RuntimeException;

/**
 * Read-only access to installer assets on a (private) GitHub repository.
 *
 * The repo stays private: customers never see it and never need a GitHub
 * account. After DownloadService has checked the licence, signedUrl() asks
 * GitHub for the asset with our token; GitHub answers with a redirect to a
 * short-lived pre-signed URL (valid for a few minutes), which we hand to the
 * browser. The token itself never leaves the server.
 *
 * Config (.env):
 *   GITHUB_RELEASES_REPO   owner/name, e.g. hmzi67/point-of-sale
 *   GITHUB_RELEASES_TOKEN  fine-grained PAT, this repo only, Contents: read
 *                          (required for a private repo; optional for a public one)
 */
final class GitHubReleases
{
    private const API = 'https://api.github.com';
    private const TIMEOUT_SECONDS = 15;

    private string $repo;
    private ?string $token;

    public function __construct(?string $repo = null, ?string $token = null)
    {
        $this->repo  = $repo ?? Env::require('GITHUB_RELEASES_REPO');
        $this->token = $token ?? Env::get('GITHUB_RELEASES_TOKEN');

        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $this->repo)) {
            throw new RuntimeException('GITHUB_RELEASES_REPO must look like owner/name');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP curl extension is required for GitHub downloads');
        }
    }

    /**
     * All assets on a release tag.
     *
     * @return list<array{id:int,name:string,size:int,url:string}>
     */
    public function assets(string $tag): array
    {
        [$status, , $body] = $this->request(
            self::API . '/repos/' . $this->repo . '/releases/tags/' . rawurlencode($tag),
            'application/vnd.github+json'
        );
        if ($status === 404) {
            throw new RuntimeException("GitHub release not found: {$tag} (check the tag, the repo name and the token's access)");
        }
        if ($status !== 200) {
            throw new RuntimeException("GitHub release lookup failed with HTTP {$status}");
        }

        $release = json_decode($body, true);
        if (!is_array($release) || !isset($release['assets']) || !is_array($release['assets'])) {
            throw new RuntimeException('Unexpected response from GitHub release lookup');
        }

        $assets = [];
        foreach ($release['assets'] as $asset) {
            $assets[] = [
                'id'   => (int) $asset['id'],
                'name' => (string) $asset['name'],
                'size' => (int) $asset['size'],
                'url'  => (string) $asset['url'],   // API URL, not browser_download_url
            ];
        }
        return $assets;
    }

    /**
     * A short-lived, pre-signed URL the browser can download the asset from.
     * Two API calls per download, well inside GitHub's 5,000/hour token limit.
     */
    public function signedUrl(string $tag, string $assetName): string
    {
        $match = null;
        foreach ($this->assets($tag) as $asset) {
            if ($asset['name'] === $assetName) {
                $match = $asset;
                break;
            }
        }
        if ($match === null) {
            throw new RuntimeException("Asset {$assetName} is not on GitHub release {$tag}");
        }

        // Asking for the binary makes GitHub reply 302 with the signed URL in
        // Location. Redirects are NOT followed: we want the URL, not the file.
        [$status, $headers] = $this->request($match['url'], 'application/octet-stream');
        $location = $headers['location'] ?? '';

        if (!in_array($status, [301, 302, 303, 307, 308], true) || !str_starts_with($location, 'https://')) {
            throw new RuntimeException("GitHub did not return a download redirect (HTTP {$status})");
        }
        return $location;
    }

    /**
     * @return array{0:int,1:array<string,string>,2:string} status, lower-cased headers, body
     */
    private function request(string $url, string $accept): array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => array_merge([
                'Accept: ' . $accept,
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: diwan-web',
            ], $this->token !== null ? ['Authorization: Bearer ' . $this->token] : []),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Could not reach GitHub: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status === 401 || $status === 403) {
            throw new RuntimeException("GitHub refused the token (HTTP {$status}) — check GITHUB_RELEASES_TOKEN");
        }
        return [$status, $headers, (string) $body];
    }
}
