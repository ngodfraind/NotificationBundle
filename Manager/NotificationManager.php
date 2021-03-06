<?php

namespace Icap\NotificationBundle\Manager;

use Icap\NotificationBundle\Entity\FollowerResource;
use Claroline\CoreBundle\Event\Log\NotifiableInterface;
use Icap\NotificationBundle\Entity\Notification;
use Icap\NotificationBundle\Entity\NotificationViewer;
use Doctrine\ORM\EntityManager;
use Icap\NotificationBundle\Event\Notification\NotificationCreateDelegateViewEvent;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Exception\NotValidCurrentPageException;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Icap\NotificationBundle\Entity\ColorChooser;
use Symfony\Component\DependencyInjection\Container;

class NotificationManager
{
    protected $em;
    protected $security;
    protected $container;

    /**
     * @return \Icap\NotificationBundle\Entity\Notification repository
     */
    protected function getNotificationRepository()
    {
        return $this->getEntityManager()->getRepository('IcapNotificationBundle:Notification');
    }

    /**
     * @return \Icap\NotificationBundle\Entity\NotificationViewer repository
     */
    protected function getNotificationViewerRepository()
    {
        return $this->getEntityManager()->getRepository('IcapNotificationBundle:NotificationViewer');
    }

    /**
     * @return \Icap\NotificationBundle\Entity\FollowerResource repository
     */
    protected function getFollowerResourceRepository()
    {
        return $this->getEntityManager()->getRepository('IcapNotificationBundle:FollowerResource');
    }

    protected function getUsersToNotifyForNotifiable(NotifiableInterface $notifiable)
    {
        $userIds = array();
        if ($notifiable->getSendToFollowers() && $notifiable->getResource() !== null) {
            $userIds = $this->getFollowersByResourceIdAndClass(
                $notifiable->getResource()->getId(),
                $notifiable->getResource()->getClass()
            );
        }

        $includeUserIds = $notifiable->getIncludeUserIds();
        if (!empty($includeUserIds)) {
            $userIds = array_merge($userIds, $includeUserIds);
        }

        $userIds        = array_unique($userIds);
        $excludeUserIds = $notifiable->getExcludeUserIds();
        $removeUserIds  = array();

        if (!empty($excludeUserIds)) {
            $userIds = array_diff($userIds, $excludeUserIds);
        }

        $doer = $notifiable->getDoer();
        if (!empty($doer) && is_a($doer, 'Claroline\CoreBundle\Entity\User')) {
            array_push($removeUserIds, $doer->getId());
        }

        $userIds = array_diff($userIds, $removeUserIds);

        return $userIds;
    }

    /**
     * Constructor
     *
     * @param \Symfony\Component\DependencyInjection\Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->em        = $container->get('icap.notification.orm.entity_manager');
        $this->security  = $container->get('security.context');
    }

    /**
     * @return EntityManager
     */
    public function getEntityManager()
    {
        return $this->em;
    }

    /**
     * Get Hash for a given object which must implement notifiable interface
     *
     * @param int    $resourceId
     * @param string $resourceClass
     *
     * @return string The generated hash
     */
    public function getHash($resourceId, $resourceClass)
    {
        $raw = sprintf('%s_%s',
            $resourceClass,
            $resourceId
        );

        return md5($raw);
    }

    /**
     * @param int    $resourceId
     * @param string $resourceClass
     *
     * @return mixed
     */
    public function getFollowersByResourceIdAndClass($resourceId, $resourceClass)
    {
        $followerResults = $this->getFollowerResourceRepository()->
            findFollowersByResourceIdAndClass($resourceId, $resourceClass);
        $followerIds = array();
        foreach ($followerResults as $followerResult) {
            array_push($followerIds, $followerResult['id']);
        }

        return $followerIds;
    }

    /**
     * Create new Tag given its name
     *
     * @param string        $actionKey
     * @param string        $iconKey
     * @param integer|null  $resourceId
     * @param array         $details
     * @param object|null   $doer
     *
     * @internal param \Icap\NotificationBundle\Entity\NotifiableInterface $notifiable
     *
     * @return Notification
     */
    public function createNotification($actionKey, $iconKey, $resourceId = null, $details = array(), $doer = null)
    {
        $notification = new Notification();
        $notification->setActionKey($actionKey);
        $notification->setIconKey($iconKey);
        $notification->setResourceId($resourceId);

        $doerId  = null;

        if ($doer === null) {
            $securityToken = $this->security->getToken();

            if (null !== $securityToken) {
                $doer = $securityToken->getUser();
            }
        }

        if (is_a($doer,'Claroline\CoreBundle\Entity\User')) {
            $doerId = $doer->getId();
        }

        if (!isset($details['doer']) && !empty($doerId)) {
            $details['doer'] = array(
                'id'        => $doerId,
                'firstName' =>  $doer->getFirstName(),
                'lastName'  => $doer->getLastName(),
                'avatar'    => $doer->getPicture(),
                'publicUrl' => $doer->getPublicUrl()
            );
        }
        $notification->setDetails($details);
        $notification->setUserId($doerId);

        $this->getEntityManager()->persist($notification);
        $this->getEntityManager()->flush();

        return $notification;
    }

    /**
     * Creates a notification viewer for every user in the list of people to be notified
     *
     * @param Notification        $notification
     * @param NotifiableInterface $notifiable
     *
     * @return \Icap\NotificationBundle\Entity\Notification
     */
    public function notifyUsers(Notification $notification, $userIds)
    {
        if (count($userIds)>0) {
            foreach ($userIds as $userId) {
                if ($userId !== null) {
                    $notificationViewer = new NotificationViewer();
                    $notificationViewer->setNotification($notification);
                    $notificationViewer->setViewerId($userId);
                    $notificationViewer->setStatus(false);

                    $this->getEntityManager()->persist($notificationViewer);
                }
            }
        }
        $this->getEntityManager()->flush();

        return $notification;
    }

    /**
     * Creates a notification and notifies the concerned users
     *
     * @param  NotifiableInterface $notifiable
     * @return Notification
     */
    public function createNotificationAndNotify(NotifiableInterface $notifiable)
    {
        $userIds = $this->getUsersToNotifyForNotifiable($notifiable);
        $notification = null;
        if (count($userIds)>0) {
            $resourceId = null;
            if ($notifiable->getResource() !== null) {
                $resourceId = $notifiable->getResource()->getId();
            }

            $notification = $this->createNotification(
                $notifiable->getActionKey(),
                $notifiable->getIconKey(),
                $resourceId,
                $notifiable->getNotificationDetails(),
                $notifiable->getDoer()
            );
            $this->notifyUsers($notification, $userIds);
        }

        return $notification;
    }

    /**
     * Retrieves the notifications list
     *
     * @param  int   $userId
     * @param  int   $page
     * @param  int   $maxResult
     * @return query
     */
    public function getUserNotificationsList($userId, $page = 1, $maxResult = -1)
    {
        $query = $this->getNotificationViewerRepository()->findUserNotificationsQuery($userId);
        $adapter = new DoctrineORMAdapter($query, false);
        $pager   = new Pagerfanta($adapter);
        $pager->setMaxPerPage($maxResult);

        try {
            $pager->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            throw new NotFoundHttpException();
        }

        $views = $this->renderNotifications($pager->getCurrentPageResults());

        return array(
            'pager' => $pager,
            'notificationViews' => $views
        );
    }

    protected function renderNotifications($notificationsViews)
    {
        $views = array();
        $colorChooser = new ColorChooser();
        $systemName = $this->container->getParameter('icap_notification.system_name');
        $unviewedNotificationIds = array();
        foreach ($notificationsViews as $notificationView) {
            $notification = $notificationView->getNotification();
            $iconKey = $notification->getIconKey();
            if (!empty($iconKey)) {
                $notificationColor = $colorChooser->getColorForName($iconKey);
                $notification->setIconColor($notificationColor);
            }
            $eventName = 'create_notification_item_'.$notification->getActionKey();
            $event     = new NotificationCreateDelegateViewEvent($notificationView, $systemName);

            /** @var EventDispatcher $eventDispatcher */
            $eventDispatcher = $this->container->get('event_dispatcher');
            if ($eventDispatcher->hasListeners($eventName)) {
                $event = $eventDispatcher->dispatch($eventName, $event);
                $views[$notificationView->getId().''] = $event->getResponseContent();
            }
            if ($notificationView->getStatus() == false) array_push($unviewedNotificationIds, $notificationView->getId());
        }
        $this->markNotificationsAsViewed($unviewedNotificationIds);

        return $views;
    }

    /**
     * @param int    $userId
     * @param int    $resourceId
     * @param string $resourceClass
     *
     * @return
     */
    public function getFollowerResource($userId, $resourceId, $resourceClass)
    {
        $followerResource = $this->getFollowerResourceRepository()->findOneBy(
            array(
                'followerId' => $userId,
                'hash' => $this->getHash($resourceId, $resourceClass)
            )
        );

        return $followerResource;
    }

    public function getTaggedUsersFromText($text)
    {

    }

    /**
     * @param $userId
     * @param $resourceId
     * @param $resourceClass
     * @return FollowerResource
     */
    public function followResource($userId, $resourceId, $resourceClass)
    {
        $followerResource = new FollowerResource();
        $followerResource->setFollowerId($userId);
        $followerResource->setResourceId($resourceId);
        $followerResource->setHash($this->getHash($resourceId, $resourceClass));
        $followerResource->setResourceClass($resourceClass);

        $this->getEntityManager()->persist($followerResource);
        $this->getEntityManager()->flush();

        return $followerResource;
    }

    /**
     * @param $userId
     * @param $resourceId
     * @param $resourceClass
     * @return mixed
     */
    public function unfollowResource($userId, $resourceId, $resourceClass)
    {
        $followerResource = $this->getFollowerResource($userId, $resourceId, $resourceClass);

        if (!empty($followerResource)) {
            $this->getEntityManager()->remove($followerResource);
            $this->getEntityManager()->flush();
        }

        return $followerResource;
    }

    /**
     * @param $notificationViewIds
     */
    public function markNotificationsAsViewed($notificationViewIds)
    {
        if (!empty($notificationViewIds)) {
            $this->getNotificationViewerRepository()->markAsViewed($notificationViewIds);
        }
    }

    /**
     * @param  null $viewerId
     * @return int
     */
    public function countUnviewedNotifications($viewerId = null)
    {
        if (empty($viewerId)) {
            $viewerId = $this->security->getToken()->getUser()->getId();
        }

        return intval($this->getNotificationViewerRepository()->countUnviewedNotifications($viewerId)["total"]);
    }
}
