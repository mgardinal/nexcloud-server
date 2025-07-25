<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\FederatedFileSharing\Controller;

use OCA\FederatedFileSharing\AddressHandler;
use OCA\FederatedFileSharing\FederatedShareProvider;
use OCA\FederatedFileSharing\Notifications;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCSController;
use OCP\Constants;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\Exceptions\ProviderDoesNotExistsException;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudIdManager;
use OCP\HintException;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Server;
use OCP\Share;
use OCP\Share\Exceptions\ShareNotFound;
use Psr\Log\LoggerInterface;

#[OpenAPI(scope: OpenAPI::SCOPE_FEDERATION)]
class RequestHandlerController extends OCSController {

	public function __construct(
		string $appName,
		IRequest $request,
		private FederatedShareProvider $federatedShareProvider,
		private IDBConnection $connection,
		private Share\IManager $shareManager,
		private Notifications $notifications,
		private AddressHandler $addressHandler,
		private IUserManager $userManager,
		private ICloudIdManager $cloudIdManager,
		private LoggerInterface $logger,
		private ICloudFederationFactory $cloudFederationFactory,
		private ICloudFederationProviderManager $cloudFederationProviderManager,
		private IEventDispatcher $eventDispatcher,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * create a new share
	 *
	 * @param string|null $remote Address of the remote
	 * @param string|null $token Shared secret between servers
	 * @param string|null $name Name of the shared resource
	 * @param string|null $owner Display name of the receiver
	 * @param string|null $sharedBy Display name of the sender
	 * @param string|null $shareWith ID of the user that receives the share
	 * @param int|null $remoteId ID of the remote
	 * @param string|null $sharedByFederatedId Federated ID of the sender
	 * @param string|null $ownerFederatedId Federated ID of the receiver
	 * @return Http\DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSException
	 *
	 * 200: Share created successfully
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function createShare(
		?string $remote = null,
		?string $token = null,
		?string $name = null,
		?string $owner = null,
		?string $sharedBy = null,
		?string $shareWith = null,
		?int $remoteId = null,
		?string $sharedByFederatedId = null,
		?string $ownerFederatedId = null,
	) {
		if ($ownerFederatedId === null) {
			$ownerFederatedId = $this->cloudIdManager->getCloudId($owner, $this->cleanupRemote($remote))->getId();
		}
		// if the owner of the share and the initiator are the same user
		// we also complete the federated share ID for the initiator
		if ($sharedByFederatedId === null && $owner === $sharedBy) {
			$sharedByFederatedId = $ownerFederatedId;
		}

		$share = $this->cloudFederationFactory->getCloudFederationShare(
			$shareWith,
			$name,
			'',
			$remoteId,
			$ownerFederatedId,
			$owner,
			$sharedByFederatedId,
			$sharedBy,
			$token,
			'user',
			'file'
		);

		try {
			$provider = $this->cloudFederationProviderManager->getCloudFederationProvider('file');
			$provider->shareReceived($share);
			if ($sharedByFederatedId === $ownerFederatedId) {
				$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('A new federated share with "%s" was created by "%s" and shared with "%s"', [$name, $ownerFederatedId, $shareWith]));
			} else {
				$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('A new federated share with "%s" was shared by "%s" (resource owner is: "%s") and shared with "%s"', [$name, $sharedByFederatedId, $ownerFederatedId, $shareWith]));
			}
		} catch (ProviderDoesNotExistsException $e) {
			throw new OCSException('Server does not support federated cloud sharing', 503);
		} catch (ProviderCouldNotAddShareException $e) {
			throw new OCSException($e->getMessage(), 400);
		} catch (\Exception $e) {
			throw new OCSException('internal server error, was not able to add share from ' . $remote, 500);
		}

		return new DataResponse();
	}

	/**
	 * create re-share on behalf of another user
	 *
	 * @param int $id ID of the share
	 * @param string|null $token Shared secret between servers
	 * @param string|null $shareWith ID of the user that receives the share
	 * @param int|null $remoteId ID of the remote
	 * @return Http\DataResponse<Http::STATUS_OK, array{token: string, remoteId: string}, array{}>
	 * @throws OCSBadRequestException Re-sharing is not possible
	 * @throws OCSException
	 *
	 * 200: Remote share returned
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function reShare(int $id, ?string $token = null, ?string $shareWith = null, ?int $remoteId = 0) {
		if ($token === null
			|| $shareWith === null
			|| $remoteId === null
		) {
			throw new OCSBadRequestException();
		}

		$notification = [
			'sharedSecret' => $token,
			'shareWith' => $shareWith,
			'senderId' => $remoteId,
			'message' => 'Recipient of a share ask the owner to reshare the file'
		];

		try {
			$provider = $this->cloudFederationProviderManager->getCloudFederationProvider('file');
			[$newToken, $localId] = $provider->notificationReceived('REQUEST_RESHARE', $id, $notification);
			return new DataResponse([
				'token' => $newToken,
				'remoteId' => $localId
			]);
		} catch (ProviderDoesNotExistsException $e) {
			throw new OCSException('Server does not support federated cloud sharing', 503);
		} catch (ShareNotFound $e) {
			$this->logger->debug('Share not found: ' . $e->getMessage(), ['exception' => $e]);
		} catch (\Exception $e) {
			$this->logger->debug('internal server error, can not process notification: ' . $e->getMessage(), ['exception' => $e]);
		}

		throw new OCSBadRequestException();
	}


	/**
	 * accept server-to-server share
	 *
	 * @param int $id ID of the remote share
	 * @param string|null $token Shared secret between servers
	 * @return Http\DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSException
	 * @throws ShareNotFound
	 * @throws HintException
	 *
	 * 200: Share accepted successfully
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function acceptShare(int $id, ?string $token = null) {
		$notification = [
			'sharedSecret' => $token,
			'message' => 'Recipient accept the share'
		];

		try {
			$provider = $this->cloudFederationProviderManager->getCloudFederationProvider('file');
			$provider->notificationReceived('SHARE_ACCEPTED', $id, $notification);
			$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Federated share with id "%s" was accepted', [$id]));
		} catch (ProviderDoesNotExistsException $e) {
			throw new OCSException('Server does not support federated cloud sharing', 503);
		} catch (ShareNotFound $e) {
			$this->logger->debug('Share not found: ' . $e->getMessage(), ['exception' => $e]);
		} catch (\Exception $e) {
			$this->logger->debug('internal server error, can not process notification: ' . $e->getMessage(), ['exception' => $e]);
		}

		return new DataResponse();
	}

	/**
	 * decline server-to-server share
	 *
	 * @param int $id ID of the remote share
	 * @param string|null $token Shared secret between servers
	 * @return Http\DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSException
	 *
	 * 200: Share declined successfully
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function declineShare(int $id, ?string $token = null) {
		$notification = [
			'sharedSecret' => $token,
			'message' => 'Recipient declined the share'
		];

		try {
			$provider = $this->cloudFederationProviderManager->getCloudFederationProvider('file');
			$provider->notificationReceived('SHARE_DECLINED', $id, $notification);
			$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Federated share with id "%s" was declined', [$id]));
		} catch (ProviderDoesNotExistsException $e) {
			throw new OCSException('Server does not support federated cloud sharing', 503);
		} catch (ShareNotFound $e) {
			$this->logger->debug('Share not found: ' . $e->getMessage(), ['exception' => $e]);
		} catch (\Exception $e) {
			$this->logger->debug('internal server error, can not process notification: ' . $e->getMessage(), ['exception' => $e]);
		}

		return new DataResponse();
	}

	/**
	 * remove server-to-server share if it was unshared by the owner
	 *
	 * @param int $id ID of the share
	 * @param string|null $token Shared secret between servers
	 * @return Http\DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSException
	 *
	 * 200: Share unshared successfully
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function unshare(int $id, ?string $token = null) {
		if (!$this->isS2SEnabled()) {
			throw new OCSException('Server does not support federated cloud sharing', 503);
		}

		try {
			$provider = $this->cloudFederationProviderManager->getCloudFederationProvider('file');
			$notification = ['sharedSecret' => $token];
			$provider->notificationReceived('SHARE_UNSHARED', $id, $notification);
			$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Federated share with id "%s" was unshared', [$id]));
		} catch (\Exception $e) {
			$this->logger->debug('processing unshare notification failed: ' . $e->getMessage(), ['exception' => $e]);
		}

		return new DataResponse();
	}

	private function cleanupRemote($remote) {
		$remote = substr($remote, strpos($remote, '://') + 3);

		return rtrim($remote, '/');
	}


	/**
	 * federated share was revoked, either by the owner or the re-sharer
	 *
	 * @param int $id ID of the share
	 * @param string|null $token Shared secret between servers
	 * @return Http\DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSBadRequestException Revoking the share is not possible
	 *
	 * 200: Share revoked successfully
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function revoke(int $id, ?string $token = null) {
		try {
			$provider = $this->cloudFederationProviderManager->getCloudFederationProvider('file');
			$notification = ['sharedSecret' => $token];
			$provider->notificationReceived('RESHARE_UNDO', $id, $notification);
			return new DataResponse();
		} catch (\Exception $e) {
			throw new OCSBadRequestException();
		}
	}

	/**
	 * check if server-to-server sharing is enabled
	 *
	 * @param bool $incoming
	 * @return bool
	 */
	private function isS2SEnabled($incoming = false) {
		$result = Server::get(IAppManager::class)->isEnabledForUser('files_sharing');

		if ($incoming) {
			$result = $result && $this->federatedShareProvider->isIncomingServer2serverShareEnabled();
		} else {
			$result = $result && $this->federatedShareProvider->isOutgoingServer2serverShareEnabled();
		}

		return $result;
	}

	/**
	 * update share information to keep federated re-shares in sync
	 *
	 * @param int $id ID of the share
	 * @param string|null $token Shared secret between servers
	 * @param int|null $permissions New permissions
	 * @return Http\DataResponse<Http::STATUS_OK, list<empty>, array{}>
	 * @throws OCSBadRequestException Updating permissions is not possible
	 *
	 * 200: Permissions updated successfully
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function updatePermissions(int $id, ?string $token = null, ?int $permissions = null) {
		$ncPermissions = $permissions;

		try {
			$provider = $this->cloudFederationProviderManager->getCloudFederationProvider('file');
			$ocmPermissions = $this->ncPermissions2ocmPermissions((int)$ncPermissions);
			$notification = ['sharedSecret' => $token, 'permission' => $ocmPermissions];
			$provider->notificationReceived('RESHARE_CHANGE_PERMISSION', $id, $notification);
			$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Federated share with id "%s" has updated permissions "%s"', [$id, implode(', ', $ocmPermissions)]));
		} catch (\Exception $e) {
			$this->logger->debug($e->getMessage(), ['exception' => $e]);
			throw new OCSBadRequestException();
		}

		return new DataResponse();
	}

	/**
	 * translate Nextcloud permissions to OCM Permissions
	 *
	 * @param $ncPermissions
	 * @return array
	 */
	protected function ncPermissions2ocmPermissions($ncPermissions) {
		$ocmPermissions = [];

		if ($ncPermissions & Constants::PERMISSION_SHARE) {
			$ocmPermissions[] = 'share';
		}

		if ($ncPermissions & Constants::PERMISSION_READ) {
			$ocmPermissions[] = 'read';
		}

		if (($ncPermissions & Constants::PERMISSION_CREATE)
			|| ($ncPermissions & Constants::PERMISSION_UPDATE)) {
			$ocmPermissions[] = 'write';
		}

		return $ocmPermissions;
	}

	/**
	 * change the owner of a server-to-server share
	 *
	 * @param int $id ID of the share
	 * @param string|null $token Shared secret between servers
	 * @param string|null $remote Address of the remote
	 * @param string|null $remote_id ID of the remote
	 * @return Http\DataResponse<Http::STATUS_OK, array{remote: string, owner: string}, array{}>
	 * @throws OCSBadRequestException Moving share is not possible
	 *
	 * 200: Share moved successfully
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function move(int $id, ?string $token = null, ?string $remote = null, ?string $remote_id = null) {
		if (!$this->isS2SEnabled()) {
			throw new OCSException('Server does not support federated cloud sharing', 503);
		}

		$newRemoteId = (string)($remote_id ?? $id);
		$cloudId = $this->cloudIdManager->resolveCloudId($remote);

		$qb = $this->connection->getQueryBuilder();
		$query = $qb->update('share_external')
			->set('remote', $qb->createNamedParameter($cloudId->getRemote()))
			->set('owner', $qb->createNamedParameter($cloudId->getUser()))
			->set('remote_id', $qb->createNamedParameter($newRemoteId))
			->where($qb->expr()->eq('remote_id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('share_token', $qb->createNamedParameter($token)));
		$affected = $query->executeStatement();

		if ($affected > 0) {
			return new DataResponse(['remote' => $cloudId->getRemote(), 'owner' => $cloudId->getUser()]);
		} else {
			throw new OCSBadRequestException('Share not found or token invalid');
		}
	}
}
