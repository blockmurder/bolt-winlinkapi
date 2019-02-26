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
            'email'    => 'nobody@example.com',
            'cron_interval' => 'hourly'
        ];
    }

    protected function subscribe(EventDispatcherInterface $dispatcher)
    {
        $config = $this->getConfig();
        if($config['cron_interval'] == 'daily')
        {
            $cronInterval = CronEvents::CRON_DAILY;
        }
        else if ($config['cron_interval'] == 'hourly')
        {
            $cronInterval = CronEvents::CRON_HOURLY;
        }
        else
        {
            $cronInterval = CronEvents::CRON_HOURLY;
        }

        $dispatcher->addListener($cronInterval, array($this, 'getWinLinkDataFunction'));
    }

    protected function registerTwigFunctions()
    {
        return [
            'getCallSign' => 'getCallSign',
            'geoString'    => ['geoString', ['is_safe' => ['html']]],
        ];
    }

    protected function registerTwigFilters()
    {
        return [
            'geoString'    => ['geoString', ['is_safe' => ['html']]],
        ];
    }

    public function getCallSign()
    {
        $config = $this->getConfig();
        return $config['callsign'];
    }

    public function geoString(array $geolocation = [])
    {
        $geoStringLat = "";
        $geoStringLong = "";

        if($geolocation['latitude'] == NULL || $geolocation['longitude'] == NULL)
        {
            return $geoString;
        }

        $latitude = $this->ddToDms($geolocation['latitude']);
        $longitude = $this->ddToDms($geolocation['longitude']);

        if($latitude['deg'] >= 0)
        {
            $geoStringLat = abs($latitude['deg'])."° ".$latitude['min']."' ".$latitude['sec']."\""." N";
        }
        else
        {
            $geoStringLat = abs($latitude['deg'])."° ".$latitude['min']."' ".$latitude['sec']."\""." S";
        }

        if($longitude['deg'] >= 0)
        {
            $geoStringLong = abs($longitude['deg'])."° ".$longitude['min']."' ".$longitude['sec']."\""." E";
        }
        else
        {
            $geoStringLong = abs($longitude['deg'])."° ".$longitude['min']."' ".$longitude['sec']."\""." W";
        }

        return array("latStr"=>$geoStringLat,"longStr"=>$geoStringLong);
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

        $password = "Jx7WRH2MNCsYq79";
        $accessKey = "3DA3F92FAE834F3D8F524A0F000B3629";

        $url = "http://cms.winlink.org/positionreports/get?callsign=" . $config['callsign'] . "&key=" . $accessKey . "&format=json";
        $json = file_get_contents($url);
        $data = json_decode($json, true);

        if($data == NULL || !isset($data))
        {
            $app['logger.system']->info(sprintf('No data found for %s', $url), ['event' => 'WinLinkAPI', 'source' => __CLASS__]);
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

        $app['logger.system']->info(sprintf('Added %d new position reports from winlink', $positionCounter), ['event' => 'WinLinkAPI', 'source' => __CLASS__]);

    }

    private function ddToDms($dec)
    {
        // Converts decimal format to DMS ( Degrees / minutes / seconds )
        $vars = explode(".",$dec);
        $deg = $vars[0];
        $tempma = "0.".$vars[1];

        $tempma = $tempma * 3600;
        $min = floor($tempma / 60);
        $sec = round($tempma - ($min*60),4);

        return array("deg"=>$deg,"min"=>$min,"sec"=>$sec);
    }
}
