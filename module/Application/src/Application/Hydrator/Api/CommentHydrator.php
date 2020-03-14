<?php

namespace Application\Hydrator\Api;

use Application\Comments;
use Application\Hydrator\Api\Filter\PropertyFilter;
use Application\Hydrator\Api\Strategy\AbstractHydratorStrategy;
use Application\Model\Picture;
use Application\View\Helper\UserText;
use ArrayAccess;
use Autowp\Commons\Db\Table\Row;
use Autowp\User\Model\User;
use Exception;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Hydrator\Exception\InvalidArgumentException;
use Laminas\Hydrator\Strategy\DateTimeFormatterStrategy;
use Laminas\Permissions\Acl\Acl;
use Laminas\Router\Http\TreeRouteStack;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Stdlib\ArrayUtils;
use Traversable;

use function array_keys;
use function inet_ntop;
use function is_array;

class CommentHydrator extends AbstractRestHydrator
{
    private Comments $comments;

    private Picture $picture;

    private int $userId = 0;

    private ?string $userRole;

    private User $userModel;

    private UserText $userText;

    private TableGateway $voteTable;

    private Acl $acl;

    private int $limit;

    private TreeRouteStack $router;

    public function __construct(ServiceLocatorInterface $serviceManager)
    {
        parent::__construct();

        $this->comments = $serviceManager->get(Comments::class);
        $this->router   = $serviceManager->get('HttpRouter');

        $this->picture   = $serviceManager->get(Picture::class);
        $this->userModel = $serviceManager->get(User::class);

        $this->userText = $serviceManager->get('ViewHelperManager')->get('userText');

        $this->userId = 0;

        $this->acl = $serviceManager->get(Acl::class);

        $tables          = $serviceManager->get('TableManager');
        $this->voteTable = $tables->get('comment_vote');

        $strategy = new DateTimeFormatterStrategy();
        $this->addStrategy('datetime', $strategy);

        $strategy = new Strategy\Comments($serviceManager);
        $this->addStrategy('replies', $strategy);

        $strategy = new Strategy\User($serviceManager);
        $this->addStrategy('user', $strategy);
    }

    /**
     * @param  array|Traversable $options
     * @throws InvalidArgumentException
     */
    public function setOptions($options): self
    {
        parent::setOptions($options);

        if ($options instanceof Traversable) {
            $options = ArrayUtils::iteratorToArray($options);
        } elseif (! is_array($options)) {
            throw new InvalidArgumentException(
                'The options parameter must be an array or a Traversable'
            );
        }

        if (isset($options['user_id'])) {
            $this->setUserId($options['user_id']);
        }

        if (isset($options['limit'])) {
            $this->limit = (int) $options['limit'];
        }

        return $this;
    }

    /**
     * @param int|null $userId
     */
    public function setUserId($userId = null): self
    {
        $this->userId = $userId;

        $this->getStrategy('user')->setUserId($userId);
        //$this->getStrategy('replies')->setUser($user);

        return $this;
    }

    /**
     * @param array|ArrayAccess $object
     */
    public function extract($object): ?array
    {
        $canRemove = false;
        $isModer   = false;
        $canViewIP = false;
        $role      = $this->getUserRole();
        if ($role) {
            $canRemove = $this->acl->isAllowed($role, 'comment', 'remove');
            $isModer   = $this->acl->inheritsRole($role, 'moder');
            $canViewIP = $this->acl->isAllowed($role, 'user', 'ip');
        }

        $result = [
            'id'      => (int) $object['id'],
            'deleted' => (bool) $object['deleted'],
            'item_id' => (int) $object['item_id'],
            'type_id' => (int) $object['type_id'],
        ];

        if ($this->filterComposite->filter('is_new')) {
            $result['is_new'] = $this->comments->service()->isNewMessage($object, $this->userId);
        }

        if ($this->filterComposite->filter('datetime')) {
            $addDate            = Row::getDateTimeByColumnType('timestamp', $object['datetime']);
            $result['datetime'] = $this->extractValue('datetime', $addDate);
        }

        if ($this->filterComposite->filter('user')) {
            $user = null;
            if ($object['author_id']) {
                $userRow = $this->userModel->getRow((int) $object['author_id']);
                if ($userRow) {
                    $user = $this->extractValue('user', $userRow);
                }
            }

            $result['user'] = $user;
        }

        if ($canRemove || ! $object['deleted']) {
            if ($this->filterComposite->filter('preview')) {
                $result['preview'] = $this->comments->getMessagePreview($object['message']);
            }

            if ($this->filterComposite->filter('route')) {
                $result['route'] = $this->comments->getMessageRowRoute($object);
            }

            if ($this->filterComposite->filter('text_html')) {
                $result['text_html'] = $this->userText->__invoke($object['message']);
            }

            if ($this->filterComposite->filter('vote')) {
                $result['vote'] = (int) $object['vote'];
            }

            if ($this->filterComposite->filter('user_vote')) {
                $vote = null;
                if ($this->userId) {
                    $voteRow = $this->voteTable->select([
                        'comment_id = ?' => $object['id'],
                        'user_id = ?'    => (int) $this->userId,
                    ])->current();
                    $vote    = $voteRow ? $voteRow['vote'] : null;
                }

                $result['user_vote'] = $vote;
            }
        }

        if ($this->filterComposite->filter('replies')) {
            $paginator = $this->comments->service()->getMessagesPaginator([
                'item_id'   => $object['item_id'],
                'type'      => $object['type_id'],
                'parent_id' => $object['id'],
                'order'     => 'comment_message.datetime ASC',
            ]);

            $paginator->setItemCountPerPage(500); // limit for safety

            $result['replies'] = $this->extractValue('replies', $paginator->getCurrentItems());
        }

        if ($this->filterComposite->filter('status')) {
            if ($isModer) {
                $status = null;
                if ($object['type_id'] === Comments::PICTURES_TYPE_ID) {
                    $picture = $this->picture->getRow(['id' => (int) $object['item_id']]);
                    if ($picture) {
                        switch ($picture['status']) {
                            case Picture::STATUS_ACCEPTED:
                                $status = [
                                    'class' => 'success',
                                    'name'  => 'moder/picture/acceptance/accepted',
                                ];
                                break;
                            case Picture::STATUS_INBOX:
                                $status = [
                                    'class' => 'warning',
                                    'name'  => 'moder/picture/acceptance/inbox',
                                ];
                                break;
                            case Picture::STATUS_REMOVED:
                                $status = [
                                    'class' => 'danger',
                                    'name'  => 'moder/picture/acceptance/removed',
                                ];
                                break;
                            case Picture::STATUS_REMOVING:
                                $status = [
                                    'class' => 'danger',
                                    'name'  => 'moder/picture/acceptance/removing',
                                ];
                                break;
                        }
                    }
                }

                $result['status'] = $status;
            }
        }

        if ($this->filterComposite->filter('page') && $this->limit > 0) {
            $result['page'] = $this->comments->service()->getMessagePage($object, $this->limit);
        }

        if ($canViewIP) {
            $result['ip'] = $object['ip'] ? inet_ntop($object['ip']) : null;
        }

        return $result;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param array|ArrayAccess $object
     * @throws Exception
     */
    public function hydrate(array $data, $object)
    {
        throw new Exception("Not supported");
    }

    public function setFields(array $fields): self
    {
        $this->getFilter()->addFilter('fields', new PropertyFilter(array_keys($fields)));

        foreach ($fields as $name => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (! isset($this->strategies[$name])) {
                continue;
            }

            $strategy = $this->strategies[$name];

            if ($strategy instanceof AbstractHydratorStrategy) {
                $strategy->setFields($value);
            }
        }

        if (isset($fields['replies'])) {
            $strategy = $this->strategies['replies'];

            if ($strategy instanceof AbstractHydratorStrategy) {
                $strategy->setFields($fields);
            }
        }

        return $this;
    }

    private function getUserRole(): ?string
    {
        if (! $this->userId) {
            return null;
        }

        if (! isset($this->userRole)) {
            $this->userRole = $this->userModel->getUserRole($this->userId);
        }

        return $this->userRole;
    }
}
