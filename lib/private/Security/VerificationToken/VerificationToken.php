<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OC\Security\VerificationToken;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IUser;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\Security\VerificationToken\InvalidTokenException;
use OCP\Security\VerificationToken\IVerificationToken;
use function json_encode;

class VerificationToken implements IVerificationToken {
	protected const TOKEN_LIFETIME = 60 * 60 * 24 * 7;

	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
		private ITimeFactory $timeFactory,
		private ISecureRandom $secureRandom,
		private IJobList $jobList,
	) {
	}

	/**
	 * @throws InvalidTokenException
	 */
	protected function throwInvalidTokenException(int $code): void {
		throw new InvalidTokenException($code);
	}

	public function check(
		string $token,
		?IUser $user,
		string $subject,
		string $passwordPrefix = '',
		bool $expiresWithLogin = false,
	): void {
		if ($user === null || !$user->isEnabled()) {
			$this->throwInvalidTokenException(InvalidTokenException::USER_UNKNOWN);
		}

		$encryptedToken = $this->config->getUserValue($user->getUID(), 'core', $subject, null);
		if ($encryptedToken === null) {
			$this->throwInvalidTokenException(InvalidTokenException::TOKEN_NOT_FOUND);
		}

		try {
			$decryptedToken = $this->crypto->decrypt($encryptedToken, $passwordPrefix . $this->config->getSystemValueString('secret'));
		} catch (\Exception $e) {
			// Retry with empty secret as a fallback for instances where the secret might not have been set by accident
			try {
				$decryptedToken = $this->crypto->decrypt($encryptedToken, $passwordPrefix);
			} catch (\Exception $e2) {
				$this->throwInvalidTokenException(InvalidTokenException::TOKEN_DECRYPTION_ERROR);
			}
		}

		$splitToken = explode(':', $decryptedToken);
		if (count($splitToken) !== 2) {
			$this->throwInvalidTokenException(InvalidTokenException::TOKEN_INVALID_FORMAT);
		}

		if ($splitToken[0] < ($this->timeFactory->getTime() - self::TOKEN_LIFETIME)
			|| ($expiresWithLogin && $user->getLastLogin() > $splitToken[0])) {
			$this->throwInvalidTokenException(InvalidTokenException::TOKEN_EXPIRED);
		}

		if (!hash_equals($splitToken[1], $token)) {
			$this->throwInvalidTokenException(InvalidTokenException::TOKEN_MISMATCH);
		}
	}

	public function create(
		IUser $user,
		string $subject,
		string $passwordPrefix = '',
	): string {
		$token = $this->secureRandom->generate(
			21,
			ISecureRandom::CHAR_DIGITS
			. ISecureRandom::CHAR_LOWER
			. ISecureRandom::CHAR_UPPER
		);
		$tokenValue = $this->timeFactory->getTime() . ':' . $token;
		$encryptedValue = $this->crypto->encrypt($tokenValue, $passwordPrefix . $this->config->getSystemValueString('secret'));
		$this->config->setUserValue($user->getUID(), 'core', $subject, $encryptedValue);
		$jobArgs = json_encode([
			'userId' => $user->getUID(),
			'subject' => $subject,
			'pp' => $passwordPrefix,
			'notBefore' => $this->timeFactory->getTime() + self::TOKEN_LIFETIME * 2, // multiply to provide a grace period
		]);
		$this->jobList->add(CleanUpJob::class, $jobArgs);

		return $token;
	}

	public function delete(string $token, IUser $user, string $subject): void {
		$this->config->deleteUserValue($user->getUID(), 'core', $subject);
	}
}
