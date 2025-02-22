<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserOIDC\Controller;

use Id4me\RP\Exception\InvalidAuthorityIssuerException;
use Id4me\RP\Exception\OpenIdDnsRecordNotFoundException;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\Id4Me;
use OCA\UserOIDC\Db\Id4MeMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

require_once __DIR__ . '/../../vendor/autoload.php';
use Id4me\RP\Service;
use Id4me\RP\Exception\InvalidOpenIdDomainException;
use Id4me\RP\Model\OpenIdConfig;
use Psr\Log\LoggerInterface;

class Id4meController extends BaseOidcController {
	private const STATE = 'oidc.state';
	private const NONCE = 'oidc.nonce';
	private const AUTHNAME = 'oidc.authname';

	/** @var ISecureRandom */
	private $random;
	/** @var ISession */
	private $session;
	/** @var IClientService */
	private $clientService;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var UserMapper */
	private $userMapper;
	/** @var IUserSession */
	private $userSession;
	/** @var IUserManager */
	private $userManager;
	/** @var Id4MeMapper */
	private $id4MeMapper;
	/** @var Service */
	private $id4me;
	/** @var IL10N */
	private $l10n;
	/** @var LoggerInterface */
	private $logger;
	/** @var ICrypto */
	private $crypto;

	public function __construct(
		IRequest $request,
		ISecureRandom $random,
		ISession $session,
		IConfig $config,
		IL10N $l10n,
		IClientService $clientService,
		IURLGenerator $urlGenerator,
		UserMapper $userMapper,
		IUserSession $userSession,
		IUserManager $userManager,
		HttpClientHelper $clientHelper,
		Id4MeMapper $id4MeMapper,
		LoggerInterface $logger,
		ICrypto $crypto
	) {
		parent::__construct($request, $config);

		$this->random = $random;
		$this->session = $session;
		$this->clientService = $clientService;
		$this->urlGenerator = $urlGenerator;
		$this->userMapper = $userMapper;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->id4me = new Service($clientHelper);
		$this->id4MeMapper = $id4MeMapper;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->crypto = $crypto;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 */
	public function showLogin() {
		$response = new Http\TemplateResponse('user_oidc', 'id4me/login', [], 'guest');

		$csp = new Http\ContentSecurityPolicy();
		$csp->addAllowedFormActionDomain('*');

		$response->setContentSecurityPolicy($csp);

		return $response;
	}

	/**
	 * @PublicPage
	 * @UseSession
	 * @BruteForceProtection(action=userOidcId4MeLogin)
	 *
	 * @param string $domain
	 * @return RedirectResponse|TemplateResponse
	 */
	public function login(string $domain) {
		try {
			$authorityName = $this->id4me->discover($domain);
		} catch (InvalidOpenIdDomainException | OpenIdDnsRecordNotFoundException $e) {
			$message = $this->l10n->t('Invalid OpenID domain');
			return $this->buildErrorTemplateResponse($message, Http::STATUS_BAD_REQUEST, ['invalid_openid_domain' => $domain]);
		}
		try {
			$openIdConfig = $this->id4me->getOpenIdConfig($authorityName);
		} catch (InvalidAuthorityIssuerException $e) {
			$message = $this->l10n->t('Invalid authority issuer');
			return $this->buildErrorTemplateResponse($message, Http::STATUS_BAD_REQUEST, ['invalid_authority_issuer' => $authorityName]);
		}

		try {
			$id4Me = $this->id4MeMapper->findByIdentifier($authorityName);
		} catch (DoesNotExistException $e) {
			$id4Me = $this->registerClient($authorityName, $openIdConfig);
		} catch (MultipleObjectsReturnedException $e) {
			$message = $this->l10n->t('Multiple authority found');
			return $this->buildErrorTemplateResponse($message, Http::STATUS_BAD_REQUEST, ['multiple_authority_found' => $authorityName]);
		}

		$state = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::STATE, $state);

		$nonce = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::NONCE, $nonce);

		$this->session->set(self::AUTHNAME, $authorityName);
		$this->session->close();

		$data = [
			'client_id' => $id4Me->getClientId(),
			'response_type' => 'code',
			'scope' => 'openid email profile',
			'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.id4me.code'),
			'state' => $state,
			'nonce' => $nonce,
		];

		$url = $openIdConfig->getAuthorizationEndpoint() . '?' . http_build_query($data);
		return new RedirectResponse($url);
	}

	private function registerClient(string $authorityName, OpenIdConfig $openIdConfig): Id4Me {
		$client = $this->id4me->register($openIdConfig, 'Nextcloud test', $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.id4me.code'), 'native');

		$id4Me = new Id4Me();
		$id4Me->setIdentifier($authorityName);
		$id4Me->setClientId($client->getClientId());
		$encryptedClientSecret = $this->crypto->encrypt($client->getClientSecret());
		$id4Me->setClientSecret($encryptedClientSecret);

		return $this->id4MeMapper->insert($id4Me);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 * @BruteForceProtection(action=userOidcId4MeCode)
	 *
	 * @param string $state
	 * @param string $code
	 * @param string $scope
	 * @return JSONResponse|RedirectResponse|TemplateResponse
	 * @throws \Exception
	 */
	public function code(string $state = '', string $code = '', string $scope = '') {
		if ($this->session->get(self::STATE) !== $state) {
			$this->logger->debug('state does not match');

			$message = $this->l10n->t('The received state does not match the expected value.');
			if ($this->isDebugModeEnabled()) {
				$responseData = [
					'error' => 'invalid_state',
					'error_description' => $message,
					'got' => $state,
					'expected' => $this->session->get(self::STATE),
				];
				return new JSONResponse($responseData, Http::STATUS_FORBIDDEN);
			}
			// we know debug mode is off, always throttle
			return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['reason' => 'state does not match'], true);
		}

		$authorityName = $this->session->get(self::AUTHNAME);
		try {
			$openIdConfig = $this->id4me->getOpenIdConfig($authorityName);
		} catch (InvalidAuthorityIssuerException $e) {
			$message = $this->l10n->t('Invalid authority issuer');
			return $this->buildErrorTemplateResponse($message, Http::STATUS_BAD_REQUEST, ['invalid_authority_issuer' => $authorityName]);
		}

		try {
			$id4Me = $this->id4MeMapper->findByIdentifier($authorityName);
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			$message = $this->l10n->t('Authority not found');
			return $this->buildErrorTemplateResponse($message, Http::STATUS_BAD_REQUEST, ['authority_not_found' => $authorityName]);
		}

		try {
			$id4meClientSecret = $this->crypto->decrypt($id4Me->getClientSecret());
		} catch (\Exception $e) {
			$this->logger->error('Failed to decrypt the id4me client secret', ['exception' => $e]);
			$message = $this->l10n->t('Failed to decrypt the ID4ME provider client secret');
			return $this->buildErrorTemplateResponse($message, Http::STATUS_BAD_REQUEST, [], false);
		}

		$client = $this->clientService->newClient();
		$result = $client->post(
			$openIdConfig->getTokenEndpoint(),
			[
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode($id4Me->getClientId() . ':' . $id4meClientSecret)
				],
				'body' => [
					'code' => $code,
					'client_id' => $id4Me->getClientId(),
					'client_secret' => $id4meClientSecret,
					'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.id4me.code'),
					'grant_type' => 'authorization_code',
				],
			]
		);

		$data = json_decode($result->getBody(), true);

		// Decode header and token
		[$header, $payload, $signature] = explode('.', $data['id_token']);
		$plainHeaders = json_decode(base64_decode($header), true);
		$plainPayload = json_decode(base64_decode($payload), true);

		/** TODO: VALIATE SIGNATURE! */

		// TODO: validate expiration

		// Verify audience
		if ($plainPayload['aud'] !== $id4Me->getClientId()) {
			$message = $this->l10n->t('The audience does not match ours');
			return $this->build403TemplateResponse($message, Http::STATUS_FORBIDDEN, ['invalid_audience' => $plainPayload['aud']]);
		}

		// TODO: VALIDATE NONCE (if set)

		// Insert or update user
		$backendUser = $this->userMapper->getOrCreate($id4Me->getId(), $plainPayload['sub'], true);
		$user = $this->userManager->get($backendUser->getUserId());

		$this->userSession->setUser($user);
		$this->userSession->completeLogin($user, ['loginName' => $user->getUID(), 'password' => '']);
		$this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID());

		return new RedirectResponse(\OC_Util::getDefaultPageUrl());
	}
}
