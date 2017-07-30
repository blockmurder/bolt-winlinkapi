<?php

namespace Bolt\Extension\Blockmurder\WinLinkAPI;

use Carbon\Carbon;
use Silex\Application;
use Bolt\Storage\Entity;
use Bolt\Events\CronEvents;
use Bolt\Extension\SimpleExtension;
use Bolt\Exception\StorageException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * WinLinkAPI extension class.
 *
 * @author blockmurder <info@blockmurder.com>
 */
class WinLinkAPIExtension extends SimpleExtension
{

    protected function getDefaultConfig()
    {
        return [
            'callsign' => 'SM6UAS',
            'username' => 'WinLink',
            'email'    => 'nobody@example.com'
        ];
    }

    protected function subscribe(EventDispatcherInterface $dispatcher)
    {
        $dispatcher->addListener(CronEvents::CRON_HOURLY, array($this, 'getWinLinkDataFunction'));
    }

    protected function registerTwigFunctions()
    {
        return [
            'getCallSign' => 'getCallSign',
        ];
    }

    public function getCallSign()
    {
        $config = $this->getConfig();
        return $config['callsign'];
    }

    /**
    * Render and return the Twig file templates/special/skippy.twig
    *
    * @return string
    */
    public function getWinLinkDataFunction()
    {
        $app = $this->getContainer();
        $config = $this->getConfig();

        $servers = array("halifax", "sandiego", "perth", "wien");
        $password = "Jx7WRH2MNCsYq79";

        foreach ($servers as &$server)
        {
            $url = "http://" . $server . ".winlink.org:8085/positionreports/get?callsign=" . $config['callsign'] . "&format=json";
            //$url = "http://mauna-loa.web/position.html";
            $json = file_get_contents($url);
            $data = json_decode($json, true);

            $errorCode = $data['ErrorCode'];

            if(isset($errorCode) && $errorCode == 0)
            {
                break;
            }
        }

        if($data == NULL || !isset($data))
        {
            return NULL;
        }

        /* reverse positions */
        $positionReports = array_reverse($data['PositionReports']);

        /* check if user $config['username'] exists, otherwise create user $config['username'] */
        $users = $app['storage']->getRepository('Bolt\Storage\Entity\Users');
        $user = $users->findOneBy(['username' => $config['username'] ]);

        if($user == NULL || !isset($user))
        {
            $newUser = new Entity\Users([
                'username'    => $config['username'],
                'displayname' => $config['username'],
                'password'    => $password,
                'email'       => $config['email'],
                'roles'       => ['guest'],
                'enabled'     => false,
            ]);

            try {
                $users->save($newUser);
            } catch (StorageException $e) {
                throw new Exception('An exception occurred saving user'.$config['username'].'.', $e->getCode(), $e);
            }

            /* replace default password with a random. We do not need to know it as it is just a dummy user */
            $app['access_control.password']->setRandomPassword($config['username']);

            /* get the created user */
            $user = $users->findOneBy(['username' => $config['username'] ]);
        }

        /* get the id of user $config['username'] */
        $ownerid = $user->getId();

        $positions = $app['storage']->getRepository('positions');

        /* find the latest entry made by winlink user */
        $oldPosition = $positions->findOneBy(['ownerid' => $ownerid, 'callsign' => $config['callsign']], ['id', 'DESC']);

        $positionCounter = 0;

        /* search latest saved position in winlink data */
        foreach ($positionReports as &$positionReport)
        {
            /* get the date of the entry */
            $date = date_create();
            date_timestamp_set($date, (intval(preg_replace('/\D/', '', $positionReport['Timestamp']))/1000));

            if($oldPosition == NULL || $date > $oldPosition->get('date'))
            {
                $newPosition = $positions->getEntityBuilder()->getEntity();

                /* set publishon date and status */
                $newPosition->setStatus('published');
                $newPosition->setDatepublish($date);
                $newPosition->set('date', $date);
                $newPosition->set('callsign', $config['callsign']);

                /* fill geolocation column */
                $geolocation = array(
                    'latitude' => $positionReport['Latitude'],
                    'longitude' => $positionReport['Longitude'],
                    'address' => 'Unknown',
                    'formatted_address' => 'Unknown' );

                $newPosition->set('geolocation', $geolocation);

                /* set title */
                $newPosition->set('title', $positionReport['Comment']);

                /* set owner id */
                $newPosition->set('ownerid', $ownerid);

                try {
                    $positions->save($newPosition);
                } catch (StorageException $e) {
                    throw new Exception('An exception occurred saving submission to ContentType table positions.', $e->getCode(), $e);
                }

                $positionCounter++;
            }
        }

        $app['logger.system']->info(sprintf('Added %d new position reports from server %s.winlink.org', $positionCounter, $server), ['event' => 'WinLinkAPI', 'source' => __CLASS__]);

    }
}
