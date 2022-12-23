# Integrate NetBox and PRTG

## Introduction
Using the built in webhooks in NetBox to call this script, NetBox can add devices to PRTG and keep the following items automatically updated:

* Device name.
* IP address.

In addition to this, if a device's status in NetBox is changed from anything other than `active` or `staged`, the device will be paused in PRTG and resumed when the status is changed back to either `active` or `staged`.

If a device is deleted from NetBox, it is automatically paused in PRTG.

This script will only ever make changes to devices in PRTG, e.g. it's a one-way sync.

When a new device is added to PRTG, it is duplicated (cloned) from an existing device, known as the PRTG template device, into a pre-defined group, known as the target group.

## Requirements
You will need a server running PHP and a web server such as Apache.

Tested against:

* NetBox v3.2.8
* PRTG 22.1.75.1594

Using:

* Ubuntu 22.04 LTS
* PHP v7.4
* Apache 2.4.41

Please raise an issue if you have success against different versions or if you experience any compatibility problems with different versions.

Note that this uses the PRTG passhash to authenticate with the PRTG API. It currently does not support PRTG API keys.

## Installation
On your server, clone this repository somewhere in your web server's document root.

To use debug, create a file in the netbox-prtg directory called `debug.txt` and ensure the user running the web server process (e.g. www-data) can write to this file.

## Configuration
### PRTG
1. Create a new group in PRTG, e.g. "From NetBox" and note the group's ID.
2. Add a new device to that group, e.g. "NetBox Device Template" and note the device's ID. You can set the IP of the device to something like 127.0.0.2. Configure any settings, such as tags. It's useful to use a tag such as "netbox" that denotes the device was added via NetBox automatically.
3. You need to have an appropriate PRTG user configured with a passhash set to access the PRTG API.

### NetBox
1. Add a new custom field with the following settings:
* Model(s): `DCIM > device`
* Name: `prtg_id`
* Label: `PRTG ID`
* Type: `Integer`
* Filter logic: `Disabled`
2. Add a new webhook with the following settings:
* Name: `NetBox PRTG Integration`
* Content types: `DCIM > device` `IPAM > IP address`
* Enabled: :white_check_mark:
* Events
  * Creations: :white_check_mark:
  * Updates: :white_check_mark:
  * Deletions: :white_check_mark:
* URL: (example) `https://my.server.com/api/netbox-prtg/`
* HTTP method: `POST`
* HTTP content type: `application/json`
* Additional headers (see NetBox-PRTG Configuration Options section below for explanation):
  <code>X-Debug: true
  X-NetBox-API-URL: https://netbox.server.com
  X-NetBox-API-Auth: abcdef0123456789abcdef0123456789abcdef01
  X-PRTG-API-URL: https://prtg.server.com
  X-PRTG-API-Auth: username=changeme&passhash=0123456789
  X-PRTG-Template-Device: 22123
  X-PRTG-Target-Group: 22456</code>
3. Create a new API token with `Write enabled`.
  
### NetBox-PRTG Configuration Options
Option                 | Explanation
-----------------------|-------------------------------------------------------------------------|
X-Debug                | Either `true` or `false` to turn debug output on or off.                |
X-NetBox-API-URL       | The URL of NetBox. Do not add a trailing slash.                         |
X-NetBox-API-Auth      | The NetBox API token to use.                                            |
X-PRTG-API-URL         | The URL of PRTG. Do not add a trailing slash.                           |
X-PRTG-API-Auth        | The username and passhash to use, provided in HTTP query string format. |
X-PRTG-Template-Device | The ID of the device added to PRTG to be used as the template.          |
X-PRTG-Target-Group    | The ID of the group added to PRTG that devices will be created in.      |

# Usage
With everything configured, add a new device to NetBox. This alone will not create a new device in PRTG. The device in NetBox must be assigned a primary management address (note that only IPv4 is currently only supported). Once this has been done, a new device in PRTG will be created in the target group. The `PRTG ID` field of the device in NetBox will be updated with the corresponding ID. You may move the device in PRTG into any other group and make any other changes.

If the primary management address is changed in NetBox, PRTG will be updated too. Similarly, if the device name in NetBox is changed, PRTG will also be updated.

Changing a device's status in NetBox will either pause or resume the device in PRTG. Any status other than `active` or `staged` will pause the device in PRTG. Setting a device's status in NetBox to either `active` or `staged` will resume the device in PRTG.

If a device is deleted from NetBox, it will not be deleted from PRTG. Instead, the device will be paused in PRTG with a message to indicate it has been deleted from NetBox.

# Troubleshooting
* Check firewall settings:
  * NetBox must be able to get to the server running NetBox-PRTG Integration.
  * The server running NetBox-PRTG Integration must be able to get the server running NetBox and the server running PRTG.
* Is the web server running NetBox-PRTG Integration using SSL?
  * You may need to disable `SSL verification` on the NetBox webhook.
* Turn on debugging:
  * Set `X-Debug` to `true` in the NetBox webhook additional HTTP headers.
  * Ensure there's a file called `debug.txt` in the same directory as index.php for NetBox-PRTG Integration. The user running the web server process needs to be able t read and write this file.
  * Review the `debug.txt` file. Note that you can clear the file by requesting the NetBox-PRTG Integration script with `?cleardebug` as a query string, e.g. `https://my.server.com/api/netbox-prtg/?cleardebug`
* Review web server's error log.
* Still stuck?
  * Raise an Issue on GitHub.
  
# Bugs and Contributions
Please report any bugs as an Issue on GitHub.

Contributions via Pull Requests are very welcome.
