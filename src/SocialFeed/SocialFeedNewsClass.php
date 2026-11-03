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

namespace Pdir\SocialFeedBundle\SocialFeed;

use Contao\CoreBundle\File\Metadata;
use Contao\CoreBundle\Image\Preview\MissingPreviewProviderException;
use Contao\CoreBundle\Image\Preview\PreviewFactory;
use Contao\CoreBundle\Image\Preview\UnableToGeneratePreviewException;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\FilesModel;
use Contao\StringUtil;
use Contao\System;
use Imagine\Exception\RuntimeException;
use Pdir\SocialFeedBundle\Model\SocialFeedModel;
use Symfony\Component\Filesystem\Path;

class SocialFeedNewsClass
{
    private string|array|bool|int|null|float $projectDir;

    private $staticUrl;

    public function parseNews($objTemplate, $arrRow, $objModule): void
    {
        if ('' !== $arrRow['social_feed_id']) {
            $container = System::getContainer();
            $this->projectDir = $container->getParameter('kernel.project_dir');
            $this->staticUrl = $container->get('contao.assets.files_context')->getStaticUrl();

            $teaser = $arrRow['teaser'];

            if ($objModule->pdir_sf_text_length > 0 && null !== $teaser) {
                $more = '';

                if (\strlen($teaser) > $objModule->pdir_sf_text_length) {
                    $more = ' ...';
                }
                $teaser = StringUtil::substrHtml($teaser, $objModule->pdir_sf_text_length).$more;
            }

            $objTemplate->sfFbAccountPicture = $arrRow['social_feed_account_picture'];
            $objTemplate->sfTextLength = $objModule->pdir_sf_text_length;
            $objTemplate->sfElementWidth = $objModule->pdir_sf_columns;
            $objTemplate->sfImages = $objModule->pdir_sf_enableImages;
            $objTemplate->teaser = $teaser;
            $objTemplate->socialFeedType = $arrRow['social_feed_type'];

            $this->addDocumentData($objTemplate, $arrRow, $objModule);

            $pictureFactory = System::getContainer()->get('contao.image.picture_factory');

            if (null !== $arrRow['social_feed_account_picture']) {
                $imagePath = FilesModel::findByUuid($arrRow['social_feed_account_picture'])->path ?? null;

                if (null === $imagePath) {
                    $objTemplate->accountPicture = '';
                }

                if (null !== $imagePath) {
                    try {
                        $pictureObj = $pictureFactory->create($this->projectDir.DIRECTORY_SEPARATOR.$imagePath);
                    } catch (RuntimeException) {
                        $pictureObj = null;
                    }

                    if (null !== $pictureObj) {
                        $objTemplate->accountPicture = $this->getTemplateData($pictureObj);
                    }
                }
            } else {
                $socialFeedAccount = SocialFeedModel::findBy('id', $arrRow['social_feed_config']);

                if (null !== $socialFeedAccount->instagram_account_picture) {
                    $image = FilesModel::findByUuid($socialFeedAccount->instagram_account_picture);
                    $size = StringUtil::deserialize($socialFeedAccount->instagram_account_picture_size);
                    $objTemplate->accountPicture = $this->getTemplateData($pictureFactory->create($this->projectDir.DIRECTORY_SEPARATOR.$image->path, $size));
                } elseif (null !== $socialFeedAccount->linkedin_account_picture) {
                    $image = FilesModel::findByUuid($socialFeedAccount->linkedin_account_picture);
                    $size = StringUtil::deserialize($socialFeedAccount->linkedin_account_picture_size);
                    $objTemplate->accountPicture = $this->getTemplateData($pictureFactory->create($this->projectDir.DIRECTORY_SEPARATOR.$image->path, $size));
                }
            }

            if (null !== $arrRow['social_feed_account'] && '' !== $arrRow['social_feed_account']) {
                $objTemplate->sfFbAccount = $arrRow['social_feed_account'];
            } else {
                $socialFeedAccount = SocialFeedModel::findBy('id', $arrRow['social_feed_config']);
                $objTemplate->sfFbAccount = $socialFeedAccount->instagram_account;
            }
        }
    }

    /**
     * Adds the imported LinkedIn document (PDF + preview images of all pages) to the template.
     *
     * The page previews are rendered on demand via the Contao core preview factory
     * (same mechanism as the download content element). Contao caches them in
     * assets/previews, so only the first request renders the PDF.
     *
     * Template variables:
     *  - sfDocument:         ['href', 'title', 'name', 'filesize', 'pageCount'] or null
     *  - sfDocumentPages:    list of image template data (use with $this->insert('image', $page))
     *  - sfDocumentLabels:   ['download', 'pages']
     */
    private function addDocumentData($objTemplate, array $arrRow, $objModule): void
    {
        $objTemplate->sfDocument = null;
        $objTemplate->sfDocumentPages = [];

        if (empty($arrRow['social_feed_document'])) {
            return;
        }

        $pdf = FilesModel::findByUuid($arrRow['social_feed_document']);

        if (null === $pdf) {
            return;
        }

        $absPath = Path::join($this->projectDir, $pdf->path);

        if (!is_file($absPath)) {
            return;
        }

        $pages = $this->renderDocumentPages($absPath, $this->getDocumentImageSize($objModule), $arrRow);

        $objTemplate->sfDocumentPages = $pages;
        $objTemplate->sfDocument = [
            'href' => $this->staticUrl.System::urlEncode($pdf->path),
            'name' => $pdf->name,
            'title' => StringUtil::specialchars(pathinfo($pdf->name, PATHINFO_FILENAME)),
            'filesize' => System::getReadableSize(filesize($absPath)),
            'pageCount' => \count($pages),
        ];

        System::loadLanguageFile('tl_news');

        $objTemplate->sfDocumentLabels = [
            'download' => $GLOBALS['TL_LANG']['tl_news']['sf_document_download'] ?? 'Download PDF',
            'pages' => $GLOBALS['TL_LANG']['tl_news']['sf_document_pages'] ?? 'Document pages',
        ];

        $GLOBALS['TL_CSS']['sf_document'] = 'bundles/pdirsocialfeed/css/sf_document.css|static';
    }

    /**
     * Returns the image size for the document page previews: the module field
     * "pdir_sf_document_imgSize", or the module image size "imgSize" if it is empty.
     *
     * @return array<int, mixed>|null
     */
    private function getDocumentImageSize($objModule): ?array
    {
        foreach (['pdir_sf_document_imgSize', 'imgSize'] as $field) {
            $size = StringUtil::deserialize($objModule->{$field} ?? null, true);

            if (array_filter($size)) {
                return $size;
            }
        }

        return null;
    }

    /**
     * Renders all pages of the PDF as figures using the Contao core preview factory.
     *
     * @param array<int, mixed>|null $size the image size configuration of the module
     *
     * @return array<int, array<string, mixed>> legacy image template data, one entry per page
     */
    private function renderDocumentPages(string $absPdfPath, ?array $size, array $arrRow): array
    {
        $container = System::getContainer();

        /** @var PreviewFactory $previewFactory */
        $previewFactory = $container->get('contao.image.preview_factory');
        $studio = $container->get('contao.image.studio');

        try {
            $previewSize = $previewFactory->getPreviewSizeFromImageSize($size);
            $previews = $previewFactory->createPreviews($absPdfPath, $previewSize);
        } catch (UnableToGeneratePreviewException|MissingPreviewProviderException $e) {
            $this->logPreviewError($absPdfPath, $e->getPrevious()?->getMessage() ?? $e->getMessage());

            return [];
        }

        if ($previews instanceof \Traversable) {
            $previews = iterator_to_array($previews, false);
        }

        // Without PDF support in Imagick/Gmagick Contao falls back to a generic SVG file icon.
        // That icon is no page preview, so we rather show nothing (the download link remains).
        if (1 === \count($previews) && 'svg' === strtolower(pathinfo($previews[0]->getPath(), PATHINFO_EXTENSION))) {
            $this->logPreviewError($absPdfPath, 'Only the fallback file icon was generated. Check the PDF support of Imagick/Gmagick (Ghostscript, policy.xml).');

            return [];
        }

        $pages = [];

        foreach ($previews as $i => $preview) {
            $figure = $studio
                ->createFigureBuilder()
                ->fromImage($preview)
                ->setSize($size)
                ->setMetadata(new Metadata([
                    Metadata::VALUE_ALT => sprintf('%s %d', $arrRow['headline'] ?? '', $i + 1),
                ]))
                ->enableLightbox(true)
                ->setLightboxGroupIdentifier('sf-document-'.$arrRow['id'])
                ->buildIfResourceExists()
            ;

            if (null !== $figure) {
                $pages[] = $figure->getLegacyTemplateData();
            }
        }

        return $pages;
    }

    private function logPreviewError(string $absPdfPath, string $message): void
    {
        System::getContainer()
            ->get('monolog.logger.contao')
            ->error(
                'Social Feed: LinkedIn - Could not generate page previews for '.Path::makeRelative($absPdfPath, $this->projectDir).': '.$message,
                ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR)]
            )
        ;
    }

    private function getTemplateData($picture)
    {
        if (null === $picture) {
            return;
        }

        return [
            'img' => $picture->getImg($this->projectDir, $this->staticUrl),
            'sources' => $picture->getSources($this->projectDir, $this->staticUrl),
        ];
    }
}
