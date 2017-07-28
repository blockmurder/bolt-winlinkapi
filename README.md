WinLink API Extension
======================

Adds a cron hourly cron job which adds the latest poition reports from WinLink
to the database.

Change the *callsign* in the config to your call sign.

Add the following contenttype to your contenttypes.yml:
```yaml
positions:
    name: Positions
    singular_name: position
    fields:
        title:
            type: text
        date:
            type: datetime
            default: "2000-01-01"
        geolocation:
            type: geolocation
    show_on_dashboard: false
    viewless: true
    default_status: publish
    searchable: false
    icon_many: "fa:globe"
    icon_one: "fa:globe"
```
