<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013, 2014
 * @copyright Pauli Järvinen 2017 - 2026
 */

namespace OCA\Music\Controller;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayer;
use OCA\Music\AppFramework\BusinessLayer\BusinessLayerException;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\AppFramework\Utility\RequestParameterExtractor;
use OCA\Music\AppFramework\Utility\RequestParameterExtractorException;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\BusinessLayer\ArtistBusinessLayer;
use OCA\Music\BusinessLayer\BookmarkBusinessLayer;
use OCA\Music\BusinessLayer\GenreBusinessLayer;
use OCA\Music\BusinessLayer\Library;
use OCA\Music\BusinessLayer\PlaylistBusinessLayer;
use OCA\Music\BusinessLayer\PodcastChannelBusinessLayer;
use OCA\Music\BusinessLayer\PodcastEpisodeBusinessLayer;
use OCA\Music\BusinessLayer\RadioStationBusinessLayer;
use OCA\Music\BusinessLayer\RecordLabelBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Db\Album;
use OCA\Music\Db\AmpacheSession;
use OCA\Music\Db\Artist;
use OCA\Music\Db\Bookmark;
use OCA\Music\Db\Entity;
use OCA\Music\Db\Genre;
use OCA\Music\Db\MatchMode;
use OCA\Music\Db\Playlist;
use OCA\Music\Db\PodcastChannel;
use OCA\Music\Db\PodcastEpisode;
use OCA\Music\Db\RadioStation;
use OCA\Music\Db\RecordLabel;
use OCA\Music\Db\SortBy;
use OCA\Music\Db\Track;
use OCA\Music\Http\Attribute\AmpacheAPI;
use OCA\Music\Http\ErrorResponse;
use OCA\Music\Http\FileResponse;
use OCA\Music\Http\RelayStreamResponse;
use OCA\Music\Http\XmlResponse;
use OCA\Music\Middleware\AmpacheException;
use OCA\Music\Service\Ampache\AmpacheAdvSearch;
use OCA\Music\Service\Ampache\AmpacheImageService;
use OCA\Music\Service\Ampache\AmpachePreferences;
use OCA\Music\Service\AudioTranscodeService;
use OCA\Music\Service\CoverService;
use OCA\Music\Service\DetailsService;
use OCA\Music\Service\FileSystemService;
use OCA\Music\Service\LastfmService;
use OCA\Music\Service\LibrarySettings;
use OCA\Music\Service\PodcastService;
use OCA\Music\Service\RadioService;
use OCA\Music\Service\Scrobbling\IScrobbler;
use OCA\Music\Service\StreamTokenService;
use OCA\Music\Utility\AppInfo;
use OCA\Music\Utility\ArrayUtil;
use OCA\Music\Utility\Random;
use OCA\Music\Utility\StringUtil;
use OCA\Music\Utility\Util;

use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

/**
 * 	// Client OCS API
 *	['name' => 'clientOcsApi#prepareCollection','url' => '/ocsApi/prepare_collection',			'verb' => 'POST'],
 *	['name' => 'clientOcsApi#collection',		'url' => '/ocsApi/collection',					'verb' => 'GET'],
 *	['name' => 'clientOcsApi#folders',			'url' => '/ocsApi/folders',					'verb' => 'GET'],
 *	['name' => 'clientOcsApi#genres',			'url' => '/ocsApi/genres',						'verb' => 'GET'],
 *	['name' => 'clientOcsApi#trackByFileId',	'url' => '/ocsApi/files/{fileId}',				'verb' => 'GET'],
 *	['name' => 'clientOcsApi#download',			'url' => '/ocsApi/files/{fileId}/download',	'verb' => 'GET'],
 *	['name' => 'clientOcsApi#filePath',			'url' => '/ocsApi/files/{fileId}/path',		'verb' => 'GET'],
 *	['name' => 'clientOcsApi#fileInfo',			'url' => '/ocsApi/files/{fileId}/info',		'verb' => 'GET'],
 *	['name' => 'clientOcsApi#fileDetails',		'url' => '/ocsApi/files/{fileId}/details',		'verb' => 'GET'],
 *	['name' => 'clientOcsApi#fileLyrics',		'url' => '/ocsApi/files/{fileId}/lyrics',		'verb' => 'GET'],
 *	['name' => 'clientOcsApi#findDetails',		'url' => '/ocsApi/details',					'verb' => 'GET'],
 *	['name' => 'clientOcsApi#getScanState',		'url' => '/ocsApi/scanstate',					'verb' => 'GET'],
 *	['name' => 'clientOcsApi#scan',				'url' => '/ocsApi/scan',						'verb' => 'POST'],
 *	['name' => 'clientOcsApi#removeScanned',	'url' => '/ocsApi/removescanned',				'verb' => 'POST'],
 *	['name' => 'clientOcsApi#resetScanned'	,	'url' => '/ocsApi/resetscanned',				'verb' => 'POST'],
 *	['name' => 'clientOcsApi#artistDetails',	'url' => '/ocsApi/artists/{artistId}/details',	'verb' => 'GET'],
 *	['name' => 'clientOcsApi#similarArtists',	'url' => '/ocsApi/artists/{artistId}/similar',	'verb' => 'GET'],
 *	['name' => 'clientOcsApi#albumDetails',		'url' => '/ocsApi/albums/{albumId}/details',	'verb' => 'GET'],
 *	['name' => 'clientOcsApi#setPlayingTrack',	'url' => '/ocsApi/tracks/{trackId}/playing',	'verb' => 'POST'],
 *	['name' => 'clientOcsApi#scrobble',			'url' => '/ocsApi/tracks/{trackId}/scrobble',	'verb' => 'POST'],
 */

class ClientOcsApiController extends OCSController {
    public function __construct(
        string $appName,
        IRequest $request,
        private TrackBusinessLayer $trackBusinessLayer,
        private GenreBusinessLayer $genreBusinessLayer,
        private Scanner $scanner,
        private CollectionService $collectionService,
        private DetailsService $detailsService,
        private FileSystemService $fileSystemService,
        private LastfmService $lastfmService,
        private Maintenance $maintenance,
        private LibrarySettings $librarySettings,
        private ?string $userId, // null case should happen only when the user has already logged out
        private Logger $logger,
        private IScrobbler $scrobbler,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
    /**
     * Prepare music collection
     *
     * @return DataResponse<Http::STATUS_OK, string>
     *
     */
    public function prepareCollection() : DataResponse {
     		$hash = $this->collectionService->getCachedJsonHash($this->user());
     		if ($hash === null) {
     			// build the collection but ignore the data for now
     			$this->collectionService->getJson($this->user());
     			$hash = $this->collectionService->getCachedJsonHash($this->user());
     }

     return new DataResponse([
         'hash' => $hash,
     ]);
     }

     #[NoAdminRequired]
     #[NoCSRFRequired]
     #[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
     /**
      * Get cached music collection
      *
      * @return DataResponse<Http::STATUS_OK, string>
      *
      */
     public function collection() : DataResponse {
         $collectionJson = $this->collectionService->getJson($this->user());
         $response = new DataResponse($collectionJson);
         $response->addHeader('Content-Type', 'application/json; charset=utf-8');

         // Instruct the client to cache the result in case it requested the collection with
         // the correct hash. The hash could be incorrect if the collection would have changed
         // between calls to prepareCollection() and collection().
         $requestHash = $this->request->getParam('hash');
         $actualHash = $this->collectionService->getCachedJsonHash($this->user());
         if (!empty($actualHash) && $requestHash === $actualHash) {
             HttpUtil::setClientCachingDays($response, 90);
         }

         return $response;
     }
}
