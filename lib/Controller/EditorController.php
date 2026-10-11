<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Britton Couchman <brittondcouchman@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilmCraftIntegration\Controller;

use OCA\FilmCraftIntegration\AppInfo\Application;
use OCA\FilmCraftIntegration\Service\Preferences;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

/**
 * The editor page: Nextcloud's header with FilmCraft in an iframe below it. The page script
 * (src/editor.ts) downloads the file over WebDAV, hands it to FilmCraft and uploads saves.
 */
class EditorController extends Controller {
	public function __construct(
		IRequest $request,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IInitialState $initialState,
		private Preferences $preferences,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/edit/{fileId}', requirements: ['fileId' => '\d+'])]
	public function edit(int $fileId): TemplateResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notFound();
		}
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$file = $userFolder->getFirstNodeById($fileId);
		if (!($file instanceof File) || !$file->isReadable() || !Application::opens($file->getName(), $file->getMimetype())) {
			return $this->notFound();
		}

		$path = $userFolder->getRelativePath($file->getPath());
		if ($path === null) {
			return $this->notFound();
		}
		$dir = dirname($path);

		$this->initialState->provideInitialState('file', [
			'fileId' => $file->getId(),
			'name' => $file->getName(),
			'path' => $path,
			'canUpdate' => $file->isUpdateable(),
			'etag' => $file->getEtag(),
			'uid' => $user->getUID(),
		]);
		$this->initialState->provideInitialState('urls', [
			'frame' => $this->staticFrameUrl($user->getDisplayName()),
			'files' => $this->urlGenerator->linkToRoute('files.view.indexViewFileid', [
				'view' => 'files',
				'fileid' => $file->getId(),
				'dir' => $dir === '' ? '/' : $dir,
			]),
		]);
		$this->initialState->provideInitialState('openByDefault', $this->preferences->openByDefault($user->getUID()));

		Util::addStyle(Application::APP_ID, 'editor');
		Util::addScript(Application::APP_ID, Application::APP_ID . '-editor');

		$response = new TemplateResponse(Application::APP_ID, 'editor', ['name' => $file->getName()]);
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFrameDomain("'self'");
		$response->setContentSecurityPolicy($csp);
		return $response;
	}

	/**
	 * The current build's frame.html (web/build-<hash>/, named in web/build.txt). A static page,
	 * so the app's relative URLs (its workers, worklets and data files) resolve inside its build
	 * folder, which a new version replaces as a whole: nothing is served from a stale cache.
	 */
	private function staticFrameUrl(string $author): string {
		$file = __DIR__ . '/../../web/build.txt';
		$build = is_file($file) ? trim((string)file_get_contents($file)) : '';
		if (preg_match('/^build-[0-9a-f]+$/', $build) !== 1) {
			$build = 'missing-build';
		}
		$query = ['host' => 'parent', 'author' => $author];
		return $this->urlGenerator->linkTo(Application::APP_ID, 'web/' . $build . '/frame.html') . '?' . http_build_query($query);
	}

	private function notFound(): TemplateResponse {
		return new TemplateResponse('core', '404', [], TemplateResponse::RENDER_AS_GUEST, Http::STATUS_NOT_FOUND);
	}
}
