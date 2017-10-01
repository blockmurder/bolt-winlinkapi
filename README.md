WinLink API Extension
=====================

Adds a cron job which gets the latest poition reports of your callsing from [WinLink](https://winlink.org/userPositions)
and adds them to the database. New positions are stored in the database table called `positions`
(see `contenttypes.yml` example).

Config
------
The following settings can be changed:

```yaml
# amateur radio call sign
callsign: SM6UAS

# User name which owns the entries added by this extension
username: WinLink
email: nobody@example.com

# Schedule (hourly, daily)
cron_interval: hourly
```

Add the following contenttype to your contenttypes.yml:
```yaml
positions:
    name: Positions
    singular_name: position
    fields:
        title:
            type: text
        callsign:
            type: text
            readonly: true
        date:
            type: datetime
            default: "2000-01-01"
        geolocation:
            type: geolocation
    relations:
        entries:
            multiple: false
            label: Select a blog entry
            order: -id
    show_on_dashboard: false
    default_status: publish
    searchable: false
    icon_many: "fa:globe"
    icon_one: "fa:globe"
```

Use
----
Postions can be fetched like any other content:
```Twig
{% setcontent positions = 'positions' where { callsign: getCallSign() } orderby 'date' %}
```
Not the `getCallSign()` which gets the current callsign defined in the config

The twig function/filter `geoString(geolocation)` returns an array of two strings:
```twig
{% set geoString = position.geolocation|geoString %}

<div class="coordinates">Latitude: {{ geoString.latStr }} </br> Longitude: {{ geoString.longStr }}</div>
```
returns
```
Latitude: 57° 39' 59.4" N
Longitude: 11° 50' 37.8" E
```
