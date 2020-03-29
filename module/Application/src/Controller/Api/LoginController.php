<?php

namespace Application\Controller\Api;

use Application\Model\UserAccount;
use Application\Service\UsersService;
use Autowp\ExternalLoginService\AbstractService;
use Autowp\ExternalLoginService\PluginManager as ExternalLoginServices;
use Autowp\Image\Storage;
use Autowp\User\Controller\Plugin\User as UserPlugin;
use Autowp\User\Model\User;
use Exception;
use Imagick;
use Laminas\ApiTools\ApiProblem\ApiProblemResponse;
use Laminas\Db\Sql;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\InputFilter\InputFilter;
use Laminas\Mvc\Controller\AbstractRestfulController;
use Laminas\Stdlib\ResponseInterface;
use Laminas\Uri\Http as HttpUri;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

use function file_get_contents;
use function trim;
use function uniqid;

/**
 * @method UserPlugin user($user = null)
 * @method Storage imageStorage()
 * @method ApiProblemResponse inputFilterResponse(InputFilter $inputFilter)
 * @method string language()
 * @method string translate(string $message, string $textDomain = 'default', $locale = null)
 */
class LoginController extends AbstractRestfulController
{
    private UsersService $service;

    private ExternalLoginServices $externalLoginServices;

    private array $hosts = [];

    private UserAccount $userAccount;

    private TableGateway $loginStateTable;

    private User $userModel;

    public function __construct(
        UsersService $service,
        ExternalLoginServices $externalLoginServices,
        array $hosts,
        UserAccount $userAccount,
        TableGateway $loginStateTable,
        User $userModel
    ) {
        $this->service               = $service;
        $this->externalLoginServices = $externalLoginServices;
        $this->hosts                 = $hosts;
        $this->userAccount           = $userAccount;
        $this->loginStateTable       = $loginStateTable;
        $this->userModel             = $userModel;
    }

    /**
     * @throws Exception
     */
    private function getExternalLoginService(string $serviceId): AbstractService
    {
        $service = $this->externalLoginServices->get($serviceId);

        if (! $service) {
            throw new Exception("Service `$serviceId` not found");
        }
        return $service;
    }

    public function servicesAction(): JsonModel
    {
        $services = [
            'facebook'    => [
                'name'  => 'Facebook',
                'icon'  => 'fa-facebook',
                'color' => '#3b5998',
            ],
            'vk'          => [
                'name'  => 'VK',
                'icon'  => 'fa-vk',
                'color' => '#43648c',
            ],
            'google-plus' => [
                'name'  => 'Google+',
                'icon'  => 'fa-google',
                'color' => '#dd4b39',
            ],
            'twitter'     => [
                'name'  => 'Twitter',
                'icon'  => 'fa-twitter',
                'color' => '#55acee',
            ],
            'github'      => [
                'name'  => 'Github',
                'icon'  => 'fa-github',
                'color' => '#000000',
            ],
        ];

        return new JsonModel([
            'items' => $services,
        ]);
    }

    /**
     * @suppress PhanDeprecatedFunction
     * @return ViewModel|ResponseInterface|array
     * @throws Exception
     */
    public function startAction()
    {
        if ($this->user()->logedIn()) {
            return $this->redirect()->toUrl($this->url()->fromRoute('login'));
        }

        $serviceId = trim($this->params()->fromQuery('type'));

        $service = $this->getExternalLoginService($serviceId);

        $url = $service->getLoginUrl(); // must be called before getState

        $this->loginStateTable->insert([
            'state'    => $service->getState(),
            'time'     => new Sql\Expression('now()'),
            'user_id'  => null,
            'language' => $this->language(),
            'service'  => $serviceId,
            'url'      => '/login',
        ]);

        return new JsonModel([
            'url' => $url,
        ]);
    }

    /**
     * @return ViewModel|ResponseInterface|array
     * @throws Exception
     */
    public function callbackAction()
    {
        $state = (string) $this->params()->fromQuery('state');
        if (! $state) { // twitter workaround
            $state = (string) $this->params()->fromQuery('oauth_token');
        }

        $stateRow = $this->loginStateTable->select([
            'state' => $state,
        ])->current();

        if (! $stateRow) {
            return $this->notFoundAction();
        }

        $params = $this->params()->fromQuery();

        if ($stateRow['language'] !== $this->language()) {
            if (! isset($this->hosts[$stateRow['language']])) {
                throw new Exception("Host {$stateRow['language']} not found");
            }

            $url = $this->url()->fromRoute('login/callback', [], [
                'force_canonical' => true,
                'query'           => $params,
                'uri'             => new HttpUri('https://' . $this->hosts[$stateRow['language']]['hostname']),
            ]);
            return $this->redirect()->toUrl($url);
        }

        $service = $this->getExternalLoginService($stateRow['service']);
        $success = $service->callback($params);
        if (! $success) {
            throw new Exception("Error processing callback");
        }

        $data = $service->getData([
            'language' => $stateRow['language'],
        ]);

        if (! $data) {
            throw new Exception("Error requesting data");
        }

        if (! $data->getExternalId()) {
            throw new Exception('external_id not found');
        }
        if (! $data->getName()) {
            throw new Exception('name not found');
        }

        $userId = $this->userAccount->getUserId($stateRow['service'], $data->getExternalId());

        if (! $userId) {
            if ($stateRow['user_id']) {
                $uRow = $this->userModel->getRow((int) $stateRow['user_id']);
                if (! $uRow) {
                    throw new Exception("Account `{$stateRow['user_id']}` not found");
                }
            } else {
                /* @phan-suppress-next-line PhanUndeclaredMethod */
                $ip = $this->getRequest()->getServer('REMOTE_ADDR');
                if (! $ip) {
                    $ip = '127.0.0.1';
                }

                $uRow = $this->service->addUser([
                    'email'    => null,
                    'password' => uniqid(),
                    'name'     => $data->getName(),
                    'ip'       => $ip,
                ], $this->language());
            }

            if (! $uRow) {
                return $this->notFoundAction();
            }

            $this->userAccount->create($stateRow['service'], $data->getExternalId(), [
                'user_id'      => $uRow['id'],
                'used_for_reg' => $stateRow['user_id'] ? 0 : 1,
                'name'         => $data->getName(),
                'link'         => $data->getProfileUrl(),
            ]);

            if (! $stateRow['user_id']) { // first login
                $photoUrl = $data->getPhotoUrl();
                if ($photoUrl) {
                    $photo = file_get_contents($photoUrl);

                    if ($photo) {
                        $imageSampler = $this->imageStorage()->getImageSampler();

                        $imagick = new Imagick();
                        if (! $imagick->readImageBlob($photo)) {
                            throw new Exception("Error loading image");
                        }
                        $format = $this->imageStorage()->getFormat('photo');
                        $imageSampler->convertImagick($imagick, null, $format);

                        $newImageId = $this->imageStorage()->addImageFromImagick($imagick, 'user', [
                            's3' => true,
                        ]);

                        $imagick->clear();

                        $oldImageId = $uRow['img'];

                        $this->userModel->getTable()->update([
                            'img' => $newImageId,
                        ], [
                            'id' => $uRow['id'],
                        ]);

                        if ($oldImageId) {
                            $this->imageStorage()->removeImage($oldImageId);
                        }
                    }
                }
            }
        } else {
            $uRow = $this->userModel->getRow((int) $userId);
            if (! $uRow) {
                throw new Exception('Not linked account row');
            }

            $this->userAccount->setAccountData(
                $stateRow['service'],
                $data->getExternalId(),
                [
                    'name' => $data->getName(),
                    'link' => $data->getProfileUrl(),
                ]
            );
        }

        //$url = $stateRow['url'];

        $this->loginStateTable->delete([
            'state' => $stateRow['state'],
        ]);

        /*$adapter = new IdAuthAdapter($this->userModel);
        $adapter->setIdentity($uRow['id']);
        $auth       = new AuthenticationService();
        $authResult = $auth->authenticate($adapter);
        if ($authResult->isValid()) {
            return $this->redirect()->toUrl($url);
        } else {
            // Invalid credentials
            throw new Exception('Error during login');
        }*/
    }
}