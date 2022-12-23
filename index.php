<?php
/**
 * Copyright (c) 2022 David Ramsden
 *
 * This software is provided 'as-is', without any express or implied
 * warranty. In no event will the authors be held liable for any damages
 * arising from the use of this software.
 *
 * Permission is granted to anyone to use this software for any purpose,
 * including commercial applications, and to alter it and redistribute it
 * freely, subject to the following restrictions:
 *
 * 1. The origin of this software must not be misrepresented; you must not
 *   claim that you wrote the original software. If you use this software
 *   in a product, an acknowledgment in the product documentation would be
 *   appreciated but is not required.
 * 2. Altered source versions must be plainly marked as such, and must not be
 *   misrepresented as being the original software.
 * 3. This notice may not be removed or altered from any source distribution.
 */

// Send a GET request with cleardebug query set to clear the debug log file.
if (isset($_GET["cleardebug"])) {
	echo "Clearing debug log.";
	
	file_put_contents("debug.txt", "");
	
	exit();
}

// Get JSON sent to us from NetBox webhook.
$json = json_decode(file_get_contents('php://input'));

// What model triggered the webhook?
switch ($json->{'model'}) {
	case "ipaddress":
		// Get the associated device data in JSON format.
		$netbox = netbox_api($json->{'data'}->{'assigned_object'}->{'device'}->{'url'});
		
		// Get the primary IPv4 device address.
		$primary_ip = $netbox->{'primary_ip4'}->{'address'};
		$primary_ip_nomask = preg_replace("/\/\d+$/", "", $primary_ip);
		// Get the device's PRTG ID.
		$prtg_id = $netbox->{'custom_fields'}->{'prtg_id'};
		// Get the device's name.
		$device_name = $netbox->{'name'};
		
		debug("Device primary IPv4 address: " . $primary_ip);
		debug("Device PRTG ID: " . $prtg_id);
	
		// No PRTG ID but has a primary IPv4 address, so we need to add this device to PRTG.
		// Then update the NetBox device with the PRTG device ID.
		if (empty($prtg_id) && !empty($primary_ip)) {
			debug("No device PRTG ID - Adding device to PRTG!");
			
			// Duplicate device (NetBox PRTG template devce) in PRTG, in to target group.
			// This will return the JSON formatted request to send to NetBox to update the NetBox device with it's PRTG device ID.
			$payload = prtg_api("/api/duplicateobject.htm?id=" . get_request_header("X-PRTG-Template-Device") . "&name=" . $device_name . "&host=" . $primary_ip_nomask . "&targetid=" . get_request_header("X-PRTG-Target-Group"));
			netbox_api($json->{'data'}->{'assigned_object'}->{'device'}->{'url'}, $payload);
			$payload = json_decode($payload);
			debug("Set NetBox device PRTG ID: " . $payload->{'custom_fields'}->{'prtg_id'});
			// Unpause the device in PRTG.
			debug("Unpausing new device in PRTG.");
			prtg_api("/api/pause.htm?id=" . $payload->{'custom_fields'}->{'prtg_id'} . "&action=1");
		} elseif (!empty($prtg_id)) {
			// Note that this will update even if the primary IPv4 address has been removed.
			debug("Device has a PRTG ID and primary IPv4 address could have changed - Updating PRTG device IP...");
			prtg_api("/api/setobjectproperty.htm?id=" . $prtg_id . "&name=host&value=" . $primary_ip_nomask);
		}
		break;
	
	case "device":
		$prtg_id = $json->{'data'}->{'custom_fields'}->{'prtg_id'};
	
		// Ignore if the PRTG ID is not set.
		if (empty($prtg_id)) {
			break;
		}
	
		switch ($json->{'event'}) {
			case "deleted":
				debug("Device was deleted. Pausing device in PRTG.");
				prtg_api("/api/pause.htm?id=" . $prtg_id . "&pausemsg=" . urlencode("Deleted from NetBox") . "&action=0");
				break;
				
			case "updated":
				// FIXME: Is this needed? This was probably only needed before we checked the event.
				// e.g. if it was a "created" event, there would be no prechange snapshot.
				if (!isset($json->{'snapshots'}->{'prechange'})) {
					break;
				}
			
				// Device name has been changed.
				// Update PRTG device name.
				if ($json->{'snapshots'}->{'prechange'}->{'name'} !== $json->{'snapshots'}->{'postchange'}->{'name'}) {
					debug("Device name has changed. Updating device name in PRTG.");
					prtg_api("/api/rename.htm?id=" . $prtg_id . "&value=" . $json->{'snapshots'}->{'postchange'}->{'name'});
				}
				
				// Device status has been changed.
				// Update PRTG device status (pause or resume).
				if ($json->{'snapshots'}->{'prechange'}->{'status'} !== $json->{'snapshots'}->{'postchange'}->{'status'}) {
					if ($json->{'snapshots'}->{'postchange'}->{'status'} === "active" || $json->{'snapshots'}->{'postchange'}->{'status'} === "staged") {
						// A NetBox device status of either "active" or "staged" == unpause in PRTG.
						debug("Device status has changed. Unpausing device in PRTG.");
						prtg_api("/api/pause.htm?id=" . $prtg_id . "&action=1");
					} else {
						// Otherwise, pause the device in PRTG.
						debug("Device status has changed. Pausing device in PRTG.");
						prtg_api("/api/pause.htm?id=" . $prtg_id . "&pausemsg=" . urlencode("NetBox status: " . $json->{'snapshots'}->{'postchange'}->{'status'}) . "&action=0");
					}
				}
				break;
			
			default:
				break;
		}
		break;
	
	default:
		break;
}

function get_request_header($header) {
	return getallheaders()[$header];
}

function debug_enabled() {
	// In the NetBox webook request, include X-Debug header with a value of true or false to enable or disable debugging.
	return filter_var(get_request_header("X-Debug"), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
}

function debug($str) {
	if (debug_enabled() === false) return;
	
	$str = preg_replace("/(passhash=)(\d+)/", "$1REDACTED", $str);
	
	file_put_contents("debug.txt", date("r") . ": " .$str . "\n", FILE_APPEND);
}

function netbox_api($url, $update = false) {
	$curl = curl_init(get_request_header("X-NetBox-API-URL") . $url);
	curl_setopt($curl, CURLOPT_HEADER, false);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json",
												 "Authorization: Token " . get_request_header("X-NetBox-API-Auth")));
	
	if ($update !== false) {
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PATCH");
		curl_setopt($curl, CURLOPT_POSTFIELDS, $update);
	}
	
	$response = curl_exec($curl);
	$info = curl_getinfo($curl);
	debug("Sending request to " . $info['url']);
	
	$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
	
	curl_close($curl);
	
	debug("NetBox API returned status: " . $status);
	
	switch ($status) {
		case 403:
			debug("NetBox API authorization failed.");
			exit();
			break;
			
		default:
			break;
	}
	
	return json_decode($response);
}

function prtg_api($url) {
	$curl = curl_init(get_request_header("X-PRTG-API-URL") . $url . "&" . get_request_header("X-PRTG-API-Auth"));
	curl_setopt($curl, CURLOPT_HEADER, true);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
	// PRTG API is *terrible*. We need to determine if we're calling the duplicate object URL.
	// If we are, we shouldn't follow the Location header automatically, because we need to parse the
	// device ID from this header.
	if (preg_match("/^\/api\/duplicateobject\.htm/", $url)) {
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
	} else {
		// Otherwise, we should follow the Location header automatically.
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
	}
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	
	$response = curl_exec($curl);
	$info = curl_getinfo($curl);
	debug("Sending request to " . $info['url']);
	
	$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
	
	curl_close($curl);

	debug("PRTG API returned status: " . $status);

	switch ($status) {
		case 401:
			debug("PRTG API username or passhash is incorrect.");
			exit();
			break;
			
		case 302:
			// If we called the duplicate object URL and we got a 302 response, we need to parse
			// out the Location header to get the duplicated device's ID.
			// We return this as JSON, formatted for sending to NetBox as a PATCH request.
			if (preg_match("/^\/api\/duplicateobject\.htm/", $url)) {
				if (preg_match("/Location: \/device.htm\?id=(\d+)\r\n/", $response, $matches)) {
					return json_encode(array(
						"custom_fields"	=> array(
							"prtg_id"	=> intval($matches[1])
						)
					));
				}
			}
			break;
			
		default:
			break;
	}
}
?>
