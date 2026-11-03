<?php

declare(strict_types=1);

/*
 * social feed bundle for Contao Open Source CMS
 *
 * Copyright (c) 2024 pdir / digital agentur // pdir GmbH
 *
 * @package    social-feed-bundle
 * @link       https://github.com/pdir/social-feed-bundle
 * @license    http://www.gnu.org/licences/lgpl-3.0.html LGPL
 * @author     Mathias Arzberger <develop@pdir.de>
 * @author     Philipp Seibt <develop@pdir.de>
 * @author     pdir GmbH <https://pdir.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Pdir\SocialFeedBundle\Importer;

use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Dbafs;
use Contao\File;
use Contao\Folder;
use Contao\NewsModel;
use Contao\StringUtil;
use Contao\System;
use LinkedIn\Client;
use Psr\Log\LogLevel;

/**
 * Imports LinkedIn document posts (PDF carousels).
 *
 * - resolves the document URN of a post via the LinkedIn Posts API
 * - downloads the PDF via the LinkedIn Documents API (the download URL is only valid for a short time)
 * - stores the PDF in the Contao file system and attaches it to the news
 *
 * The page previews are NOT rendered here. They are generated lazily on the first
 * frontend request via the Contao core preview factory (see SocialFeedNewsClass),
 * which caches them in assets/previews.
 */
class LinkedInDocumentImporter
{
    /**
     * Default version of the LinkedIn Posts/Documents API (format YYYYMM). LinkedIn supports
     * every version for about 12 months. Can be overridden per account via tl_social_feed.linkedin_api_version.
     */
    public const LINKEDIN_VERSION = '202610';

    private const DOCUMENT_URN_PREFIX = 'urn:li:document:';

    private Client $client;

    private string $projectDir;

    private bool $debug = false;

    private string $apiVersion;

    /**
     * @param string|null $apiVersion LinkedIn-Version header (YYYYMM); empty or invalid values fall back to LINKEDIN_VERSION
     */
    public function __construct(string $clientId, string $clientSecret, string $accessToken, ?string $apiVersion = null)
    {
        $this->apiVersion = self::normalizeApiVersion($apiVersion);

        $this->client = new Client($clientId, $clientSecret);
        $this->client->setAccessToken($accessToken);
        $this->client->setApiRoot('https://api.linkedin.com/rest/');
        $this->client->setApiHeaders([
            'Content-Type' => 'application/json',
            'X-Restli-Protocol-Version' => '2.0.0',
            'LinkedIn-Version' => $this->apiVersion,
        ]);

        $this->projectDir = System::getContainer()->getParameter('kernel.project_dir');
    }

    public function setDebugMode(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function getApiVersion(): string
    {
        return $this->apiVersion;
    }

    /**
     * Returns the given version if it has the format YYYYMM, otherwise the bundle default.
     */
    public static function normalizeApiVersion(?string $apiVersion): string
    {
        $apiVersion = trim((string) $apiVersion);

        if (1 === preg_match('/^\d{4}(0[1-9]|1[0-2])$/', $apiVersion)) {
            return $apiVersion;
        }

        return self::LINKEDIN_VERSION;
    }

    /**
     * Imports the document of a post and attaches it to the given news.
     *
     * @return bool true if a document was found and attached
     */
    public function importForNews(NewsModel $news, string $postUrn, string $baseFolder): bool
    {
        $result = $this->import($postUrn, $baseFolder);

        if (null === $result) {
            return false;
        }

        $news->social_feed_document = $result['pdf'];

        // offer the PDF as download via the standard Contao enclosure
        $enclosure = StringUtil::deserialize($news->enclosure, true);

        if (!\in_array($result['pdf'], $enclosure, true)) {
            $enclosure[] = $result['pdf'];
        }

        $news->addEnclosure = '1';
        $news->enclosure = serialize($enclosure);
        $news->tstamp = time();
        $news->save();

        return true;
    }

    /**
     * @return array{pdf: string, title: string}|null binary UUID of the PDF and the document title
     */
    public function import(string $postUrn, string $baseFolder): ?array
    {
        try {
            $post = $this->client->get('posts/' . rawurlencode($postUrn));
        } catch (\Throwable $e) {
            $this->log(LogLevel::ERROR, 'Could not load post ' . $postUrn . ': ' . $e->getMessage());

            return null;
        }

        $documentUrn = $post['content']['media']['id'] ?? '';

        if (!\is_string($documentUrn) || !str_starts_with($documentUrn, self::DOCUMENT_URN_PREFIX)) {
            return null; // no document post
        }

        $title = (string) ($post['content']['media']['title'] ?? 'document');

        try {
            $document = $this->client->get('documents/' . rawurlencode($documentUrn));
        } catch (\Throwable $e) {
            $this->log(LogLevel::ERROR, 'Could not load document ' . $documentUrn . ': ' . $e->getMessage());

            return null;
        }

        if ($this->debug) {
            dump('-- LinkedIn API: document data');
            dump($document);
        }

        $downloadUrl = $document['downloadUrl'] ?? null;

        if (empty($downloadUrl)) {
            $this->log(LogLevel::ERROR, 'Document ' . $documentUrn . ' has no download URL (status: ' . ($document['status'] ?? 'unknown') . ').');

            return null;
        }

        // files/social-feed/<company>/documents/<post-id>/
        $folder = rtrim($baseFolder, '/') . '/documents/' . preg_replace('/\D/', '', $postUrn) . '/';
        new Folder($folder);

        $pdfName = StringUtil::standardize(preg_replace('/\.pdf$/i', '', $title)) ?: 'document';
        $pdfPath = $folder . $pdfName . '.pdf';

        if (!file_exists($this->projectDir . '/' . $pdfPath)) {
            $content = @file_get_contents($downloadUrl);

            if (false === $content || !str_starts_with($content, '%PDF')) {
                $this->log(LogLevel::ERROR, 'Download of document ' . $documentUrn . ' failed.');

                return null;
            }

            $file = new File($pdfPath);
            $file->write($content);
            $file->close();
        }

        $pdfModel = Dbafs::addResource($pdfPath);

        if (null === $pdfModel) {
            $this->log(LogLevel::ERROR, 'Could not add document ' . $pdfPath . ' to the DBAFS.');

            return null;
        }

        $this->log(LogLevel::INFO, 'Imported document "' . $title . '" for post ' . $postUrn . '.');

        return [
            'pdf' => $pdfModel->uuid,
            'title' => $title,
        ];
    }

    private function log(string $level, string $message): void
    {
        if ($this->debug) {
            dump('-- LinkedIn document: ' . $message);
        }

        System::getContainer()
            ->get('monolog.logger.contao')
            ->log($level, 'Social Feed: LinkedIn - ' . $message, ['contao' => new ContaoContext(__METHOD__, LogLevel::ERROR === $level ? ContaoContext::ERROR : 'INFO')])
        ;
    }
}
