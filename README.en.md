# ShellyFWCheck

🇩🇪 [Deutsche Anleitung](README.md)

Firmware overview and updates for Shelly devices in Symcon: **one instance shows the installed and the available firmware of all your Shellys** and starts updates at the push of a button – for a single device, a selection or all of them. Everything runs locally via the devices themselves, without the Shelly app and without a cloud account.

Current version: 1.0 (Build 21)

**Checking changes nothing. Updates only start when you press a button and confirm, never on their own.**

## Requirements

- Symcon version 8.0 or newer
- Shelly devices of **Gen2** or newer (Plus, Pro, Mini, Gen3, Gen4). Gen1 devices are recognised but not supported.
- Symcon must be able to reach the devices by IP, and the devices need internet access themselves (they download the firmware directly from the manufacturer).

## Installation

- **Module Store:** search for "Shelly Firmware Check" and install it. The module is currently in the **beta channel** – beta modules have to be allowed in the store settings.
- **or Module Control:** Add → `https://github.com/peterchrisben/ShellyFWCheck`

## Setup

1. Add an instance → **Shelly Firmware-Check**.
2. If the devices are password protected: enter the **master password** under **Password**.
3. Press **Search devices on the network** – Shellys that were found appear marked blue under **Manage devices**. Devices that are not found (other network/VLAN) can be added there with "Add" and their IP address.
4. **Apply changes**. Shortly afterwards the name, model, installed and available version are shown in the **Overview** below.

As long as no device is entered, Symcon shows "Instance is inactive" – that is normal.

**The search does not find all devices?** The network search (mDNS) is not always complete – e.g. for devices that do not announce themselves, or when Symcon caches old entries. Then enter your **own network** under "Manage devices" at "Search address ranges" (e.g. From `192.168.1.1`, To `254`). The module then asks every address directly and finds every Shelly of Gen2 and newer.

**Shellys in a different network** (guest or IoT network, garden shed): the normal search only finds devices in the network of Symcon. Under "Manage devices" you can enter additional address ranges at "Search address ranges", e.g. From `192.168.20.1`, To `192.168.20.254`. If "To" is left empty, the whole network of the address is searched; the short form `192.168.20.0/24` works as well. At most 1024 addresses per row. Symcon must be able to reach the other network. Searching only happens with the button "Search devices on the network".

**Battery devices** (e.g. Shelly H&T): tick "Battery" in the device list. The device is then shown as "Sleeping" instead of as an error and is not counted under "Not reachable". Battery devices are only awake for about two seconds. In that time the module asks them for an update (the H&T answers in less than a second). While a device is asleep, an update is also derived from devices of the same model: if another H&T already runs a newer version, it is shown as available.

**Wake signal:** so that the module catches the right moment, select a variable in the column "Wake signal" that is written whenever the device wakes up – e.g. the temperature from the Shelly module (MQTT). When it is updated, the module queries only this device right away.

**Update for a sleeping device:** tick it and press "Update selected" (or "Start all updates"). The update is **scheduled** and starts by itself when the device next wakes up – even if the module does not yet know whether there is an update: when the device wakes up it asks for an update and starts one that exists right away – this also works for a device just added by hand that has never been awake ("Start all updates" only includes devices with a known update); it is confirmed on the wake-up after that. Without a wake signal this only works if the device happens to be awake during a check. Scheduled devices are shown in blue with their tick set in the overview. **Cancel:** remove the tick – the scheduled update is cancelled right away, "Apply changes" is not needed for this. Removing the tick "Battery" in the device list also discards the scheduled update.

**Tip for several devices:** first schedule all of them, then – if you do not want to wait – wake the devices one after another by button, ideally a few seconds apart. If several devices wake up shortly after each other, the module handles them in turn. Only during the big check of all devices ("Check now" or the automatic check, takes about half a minute) a device can be missed; it then stays scheduled and is handled when it next wakes up.

If a device has a different password: under "Manage devices" tick **Own PW** in its row and enter the password there. The user name is always "admin" on Shelly devices.

## Operation

| Button | What happens |
|---|---|
| Check now | queries all active devices |
| Search devices on the network | searches for Shellys via mDNS (and in the additional address ranges); new devices and changed IP addresses are entered under "Manage devices" (effective after "Apply changes") |
| Update selected | starts the updates for all devices ticked in the "Select" column of the overview (only devices with an available update are started) |
| Start all updates | starts all available updates one after another, with a pause in between (default 20 seconds) |

After an update has been started, the module asks every minute until the device reports the new version ("Updated"). Some updates run in several steps inside the device: after the first trigger the device restarts but still reports the old version. The module recognises this intermediate step and triggers the update again by itself after about a minute (status "Step 2"). If the version stays unchanged for 15 minutes, the device is released again ("Update available – not confirmed …") and can be started once more. If the device completes the update by itself later, the next check shows "Up to date".

A click on the **IP address** in the overview opens the web interface of the device in a new window.

The column **Auto update** shows whether automatic updating is switched on in the device itself (Off / Stable / Beta). This is a schedule inside the device; if it is on, the device updates itself, independently of this module.

To change it: tick the devices and press **Off** or **Stable (daily at midnight)** in the row "Auto update in the device for the selected ones". The module removes all existing update schedules of the device (including disabled leftovers) and, for "Stable", creates exactly one new schedule with the same structure the Shelly web interface uses. Other schedules (e.g. switching times) are not touched. Beta can only be switched on by script.

**Follow-up updates:** if a device offers the next update right after an update (e.g. 2.0.0 → 2.0.3 → 2.2.0), the module continues by itself until the latest version is installed (at most five follow-up updates). Can be switched off under "Updates".

**Push message:** under "Check, notification and display" you can select a tile visualization. When a check finds a new update, it receives a message ("Update available: name (version)"), only once per update. Without a selection there is no message. "Send test message" lets you try it out.

**Update history:** at the very bottom of the form you find the last 50 version changes with time, device, old and new version and what triggered them (module, follow-up update or "Device itself / from outside" for auto update, web interface and app).

**Control from the visualization:** with the tick "Enable control from the visualization" under "Updates" the module creates the variable "Command" (Check now / Start all updates). Without it the variable does not exist. Note: "Start all updates" runs from the visualization without confirmation.

Status in the overview: Up to date · Update available (yellow) · Update running or scheduled (blue) · Updated (green) · Not reachable / Password missing / Wrong password / Error (red) · Sleeping (battery devices, no colour).

## Variables

| Variable | Content |
|---|---|
| Updates available | number of devices with an update – e.g. as a trigger for a notification |
| Devices | number of active devices |
| Not reachable | number of devices that did not answer the last check |
| Devices with update | names of the devices with an update, comma separated |
| Last check | time of the last check |
| Overview | table of all devices for the visualization (can be switched off) |
| Command | only when enabled: Check now / Start all updates |

Variables keep the name of the language in which the instance was created.

## Script commands

```php
SFW_CheckAll($id);                      // check all devices
SFW_UpdateDevice($id, '192.168.1.50');  // start the update for one device
SFW_UpdateSelected($id, '["192.168.1.50","192.168.1.51"]'); // start updates for several devices one after another
SFW_UpdateAll($id);                     // start all available updates one after another, returns the number
SFW_Discover($id);                      // device search (only fills the open form)
SFW_SetAutoUpdate($id, '["192.168.1.50"]', 'stable'); // auto update in the device: 'off', 'stable' or 'beta'
SFW_GetDevices($id);                    // state of all devices as JSON
SFW_GetHistory($id);                    // update history as JSON (newest first); by: module, chain (follow-up update), external (device itself / from outside)
```

## Notes

- During an update the device restarts; whether a relay keeps its state depends on the setting in the device.
- Only stable firmware is offered, no beta versions.
- Like all settings, the passwords are stored in the Symcon configuration.

## License

CC BY-NC-SA 4.0 – Peter Chrisben

Shelly is a trademark of its respective owner. ShellyFWCheck is a private project and is not affiliated with the manufacturer.
