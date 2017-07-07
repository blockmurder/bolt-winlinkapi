<?php

namespace Bolt\Extension\Blockmurder\WinLinkAPI;

use Bolt\Extension\SimpleExtension;

/**
 * WinLinkAPI extension class.
 *
 * @author blockmurder <info@blockmurder.com>
 */
class WinLinkAPIExtension extends SimpleExtension
{

    /** @var Application */
    private $app;



    /**
    * {@inheritdoc}
    */
    protected function registerTwigFunctions()
    {
        return [
            'getWinLinkData'    => 'getWinLinkDataFunction'
        ];
    }

    /**
    * Render and return the Twig file templates/special/skippy.twig
    *
    * @return string
    */
    public function getWinLinkDataFunction($callsign = '')
    {
        $servers = array("halifax", "sandiego", "perth", "wien");

        foreach ($servers as &$server)
        {
            $url = "http://" . $server . ".winlink.org:8085/positionreports/get?callsign=" . $callsign . "&format=json";
            $json = file_get_contents($url);
            $data = json_decode($json, true);

            $errorCode = $data['ErrorCode'];

            if(isset($errorCode) && $errorCode == 0)
            {
                break;
            }
        }

        $date = date_create();

        foreach ($data['PositionReports'] as &$positionReport)
        {
            //echo $positionReport['Latitude'] . "  " . $positionReport['Longitude'] . "  " . $positionReport['Comment'] . "</br>";
            $timestamp = intval(preg_replace('/\D/', '', $positionReport['Timestamp']))/1000;
            date_timestamp_set($date, $timestamp);
            echo date_format($date, 'Y-m-d H:i:s.000000') . "</br>";
        }

        $this->app = $this->getContainer();
        $repo = $this->app['storage']->getRepository('positions');
        $positions = $repo->findBy(['status' => 'published'], ['datepublish', 'DESC'], 10);

        foreach ($positions as &$position)
        {
            var_dump($position->date);
        }

    }

}
