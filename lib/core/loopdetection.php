<?php
/***********************************************
* File      :   loopdetection.php
* Project   :   Z-Push
* Descr     :   detects an outgoing loop by looking
*               if subsequent requests do try to get changes
*               for the same sync key. If more than once a synckey
*               is requested, the amount of items to be sent to the mobile
*               is reduced to one. If then (again) the same synckey is
*               requested, we have most probably found the 'broken' item.
*
* Created   :   20.10.2011
*
* Copyright 2007 - 2011 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/


class LoopDetection extends InterProcessData {
    const INTERPROCESSLD = "ipldkey";
    const BROKENMSGS = "bromsgs";
    static private $processident;
    static private $processentry;
    private $ignore_messageid;
    private $broken_message_uuid;
    private $broken_message_counter;


    /**
     * Constructor
     *
     * @access public
     */
    public function LoopDetection() {
        // initialize super parameters
        $this->allocate = 204800; // 200 KB
        $this->type = 1337;
        parent::__construct();

        $this->ignore_messageid = false;
    }

    /**
     * PROCESS LOOP DETECTION
     */

    /**
     * Adds the process entry to the process stack
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionInit() {
        return $this->updateProcessStack();
    }

    /**
     * Returns a unique identifier for the internal process tracking
     *
     * @access public
     * @return string
     */
    public static function GetProcessIdentifier() {
        if (!isset(self::$processident))
            self::$processident = sprintf('%04x%04', mt_rand(0, 0xffff), mt_rand(0, 0xffff));

        return self::$processident;
    }

    /**
     * Returns a unique entry with informations about the current process
     *
     * @access public
     * @return array
     */
    public static function GetProcessEntry() {
        if (!isset(self::$processentry)) {
            self::$processentry = array();
            self::$processentry['id'] = self::GetProcessIdentifier();
            self::$processentry['time'] = self::$start;
            self::$processentry['cc'] = Request::GetCommandCode();
        }

        return self::$processentry;
    }

    /**
     * Adds an Exceptions to the process tracking
     *
     * @param Exception     $exception
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionAddException($exception) {
        // generate entry if not already there
        self::GetProcessEntry();

        if (!isset(self::$processentry['stat']))
            self::$processentry['stat'] = array();

        self::$processentry['stat'][get_class($exception)] = $exception->getCode();

        $this->updateProcessStack();
        return true;
    }

    /**
     * Adds a folderid and connected status code to the process tracking
     *
     * @param string    $folderid
     * @param int       $status
     *
     * @access public
     * @return boolean
     */
    public function ProcessLoopDetectionAddStatus($folderid, $status) {
        // generate entry if not already there
        self::GetProcessEntry();

        if ($folderid === false)
            $folderid = "hierarchy";

        if (!isset(self::$processentry['stat']))
            self::$processentry['stat'] = array();

        self::$processentry['stat'][$folderid] = $status;

        $this->updateProcessStack();

        return true;
    }

    /**
     * Indicates if a full Hierarchy Resync is necessary
     *
     * In some occasions the mobile tries to sync a folder with an invalid/not-existing ID.
     * In these cases a status exception like SYNC_STATUS_FOLDERHIERARCHYCHANGED is returned
     * so the mobile executes a FolderSync expecting that some action is taken on that folder (e.g. remove).
     *
     * If the FolderSync is not doing anything relevant, then the Sync is attempted again
     * resulting in the same error and looping between these two processes.
     *
     * This method checks if in the last process stack a Sync and FolderSync were triggered to
     * catch the loop at the 2nd interaction (Sync->FolderSync->Sync->FolderSync => ReSync)
     * Ticket: https://jira.zarafa.com/browse/ZP-5
     *
     * @access public
     * @return boolean
     *
     */
    public function ProcessLoopDetectionIsHierarchyResyncRequired() {
        $seenFailed = array();
        $seenFolderSync = false;

        $lookback = self::$start - 600; // look at the last 5 min
        foreach ($this->getProcessStack() as $se) {
            if ($se['time'] > $lookback && $se['time'] < (self::$start-1)) {
                // look for sync command
                if (isset($se['stat']) && ($se['cc'] == ZPush::COMMAND_SYNC || $se['cc'] == ZPush::COMMAND_PING)) {
                    foreach($se['stat'] as $key => $value) {
                        if (!isset($seenFailed[$key]))
                            $seenFailed[$key] = 0;
                        $seenFailed[$key]++;
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->ProcessLoopDetectionIsHierarchyResyncRequired(): seen command with Exception or folderid '%s' and code '%s'", $key, $value ));
                    }
                }
                // look for FolderSync command with previous failed commands
                if ($se['cc'] == ZPush::COMMAND_FOLDERSYNC && !empty($seenFailed) && $se['id'] != self::GetProcessIdentifier()) {
                    // a full folderresync was already triggered
                    if (isset($se['stat']) && $se['stat']['hierarchy'] == SYNC_FSSTATUS_SYNCKEYERROR) {
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->ProcessLoopDetectionIsHierarchyResyncRequired(): a full FolderReSync was already requested. Resetting fail counter.");
                        $seenFailed = array();
                    }
                    else {
                        $seenFolderSync = true;
                        if (!empty($seenFailed))
                            ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->ProcessLoopDetectionIsHierarchyResyncRequired(): seen FolderSync after other failing command");
                    }
                }
            }
        }

        $filtered = array();
        foreach ($seenFailed as $k => $count) {
            if ($count>1)
                $filtered[] = $k;
        }

        if ($seenFolderSync && !empty($filtered)) {
            ZLog::Write(LOGLEVEL_INFO, "LoopDetection->ProcessLoopDetectionIsHierarchyResyncRequired(): Potential loop detected. Full hierarchysync indicated.");
            return true;
        }

        return false;
    }

    /**
     * Inserts or updates the current process entry on the stack
     *
     * @access private
     * @return boolean
     */
    private function updateProcessStack() {
        // initialize params
        $this->InitializeParams();
        if ($this->blockMutex()) {
            $loopdata = ($this->hasData()) ? $this->getData() : array();

            // check and initialize the array structure
            $this->checkArrayStructure($loopdata, self::INTERPROCESSLD);

            $stack = $loopdata[self::$devid][self::$user][self::INTERPROCESSLD];

            // insert/update current process entry
            $nstack = array();
            $updateentry = self::GetProcessEntry();
            $found = false;

            foreach ($stack as $entry) {
                if ($entry['id'] != $updateentry['id']) {
                    $nstack[] = $entry;
                }
                else {
                    $nstack[] = $updateentry;
                    $found = true;
                }
            }

            if (!$found)
                $nstack[] = $updateentry;

            if (count($nstack) > 10)
                $nstack = array_slice($nstack, -10, 10);

            // update loop data
            $loopdata[self::$devid][self::$user][self::INTERPROCESSLD] = $nstack;
            $ok = $this->setData($loopdata);

            $this->releaseMutex();
        }
        // end exclusive block

        return true;
    }

    /**
     * Returns the current process stack
     *
     * @access private
     * @return array
     */
    private function getProcessStack() {
        // initialize params
        $this->InitializeParams();
        $stack = array();

        if ($this->blockMutex()) {
            $loopdata = ($this->hasData()) ? $this->getData() : array();

            // check and initialize the array structure
            $this->checkArrayStructure($loopdata, self::INTERPROCESSLD);

            $stack = $loopdata[self::$devid][self::$user][self::INTERPROCESSLD];

            $this->releaseMutex();
        }
        // end exclusive block

        return $stack;
    }

    /**
     * TRACKING OF BROKEN MESSAGES
     * if a previousily ignored message is streamed again to the device it's tracked here
     *
     * There are two outcomes:
     * - next uuid counter is higher than current -> message is fixed and successfully synchronized
     * - next uuid counter is the same or uuid changed -> message is still broken
     */

    /**
     * Adds a message to the tracking of broken messages
     * Being tracked means that a broken message was streamed to the device.
     * We save the latest uuid and counter so if on the next sync the counter is higher
     * the message was accepted by the device.
     *
     * @param string    $folderid   the parent folder of the message
     * @param string    $id         the id of the message
     *
     * @access public
     * @return boolean
     */
    public function SetBrokenMessage($folderid, $id) {
        if ($folderid == false || !isset($this->broken_message_uuid) || !isset($this->broken_message_counter) || $this->broken_message_uuid == false || $this->broken_message_counter == false)
            return false;

        $ok = false;
        $brokenkey = self::BROKENMSGS ."-". $folderid;

        // initialize params
        $this->InitializeParams();
        if ($this->blockMutex()) {
            $loopdata = ($this->hasData()) ? $this->getData() : array();

            // check and initialize the array structure
            $this->checkArrayStructure($loopdata, $brokenkey);

            $brokenmsgs = $loopdata[self::$devid][self::$user][$brokenkey];

            $brokenmsgs[$id] = array('uuid' => $this->broken_message_uuid, 'counter' => $this->broken_message_counter);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->SetBrokenMessage('%s', '%s'): tracking broken message", $folderid, $id));

            // update data
            $loopdata[self::$devid][self::$user][$brokenkey] = $brokenmsgs;
            $ok = $this->setData($loopdata);

            $this->releaseMutex();
        }
        // end exclusive block

        return $ok;
    }

    /**
     * Gets a list of all ids of a folder which were tracked and which were
     * accepted by the device from the last sync.
     *
     * @param string    $folderid   the parent folder of the message
     * @param string    $id         the id of the message
     *
     * @access public
     * @return array
     */
    public function GetSyncedButBeforeIgnoredMessages($folderid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("GetSyncedButBeforeIgnoredMessages('%s')",$folderid));

        if ($folderid == false || !isset($this->broken_message_uuid) || !isset($this->broken_message_counter) || $this->broken_message_uuid == false || $this->broken_message_counter == false)
            return array();

        $brokenkey = self::BROKENMSGS ."-". $folderid;
        $removeIds = array();
        $okIds = array();

        // initialize params
        $this->InitializeParams();
        if ($this->blockMutex()) {
            $loopdata = ($this->hasData()) ? $this->getData() : array();

            // check and initialize the array structure
            $this->checkArrayStructure($loopdata, $brokenkey);

            $brokenmsgs = $loopdata[self::$devid][self::$user][$brokenkey];

            if (!empty($brokenmsgs)) {
                foreach ($brokenmsgs as $id => $data) {
                    // previously broken message was sucessfully synced!
                    if ($data['uuid'] == $this->broken_message_uuid && $data['counter'] < $this->broken_message_counter) {
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->GetSyncedButBeforeIgnoredMessages('%s'): message '%s' was successfully synchronized", $folderid, $id));
                        $okIds[] = $id;
                    }

                    // if the uuid has changed this is old data which should also be removed
                    if ($data['uuid'] != $this->broken_message_uuid) {
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->GetSyncedButBeforeIgnoredMessages('%s'): stored message id '%s' for uuid '%s' is obsolete", $folderid, $id, $data['uuid']));
                        $removeIds[] = $id;
                    }
                }

                // remove data
                foreach (array_merge($okIds,$removeIds) as $id) {
                    unset($brokenmsgs[$id]);
                }

                if (empty($brokenmsgs) && isset($loopdata[self::$devid][self::$user][$brokenkey])) {
                    ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->GetSyncedButBeforeIgnoredMessages: loopdata". print_r($loopdata[self::$devid][self::$user],1));
                    unset($loopdata[self::$devid][self::$user][$brokenkey]);
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->GetSyncedButBeforeIgnoredMessages('%s'): removed folder from tracking of ignored messages", $folderid));
                }
                else {
                    // update data
                    $loopdata[self::$devid][self::$user][$brokenkey] = $brokenmsgs;
                }
                $ok = $this->setData($loopdata);
            }

            $this->releaseMutex();
        }
        // end exclusive block

        return $okIds;
    }

    /**
     * MESSAGE LOOP DETECTION
     */

    /**
     * Loop detection mechanism
     *
     *    1. request counter is higher than the previous counter (somehow default)
     *      1.1)   standard situation                                   -> do nothing
     *      1.2)   loop information exists
     *      1.2.1) request counter < maxCounter AND no ignored data     -> continue in loop mode
     *      1.2.2) request counter < maxCounter AND ignored data        -> we have already encountered issue, return to normal
     *
     *    2. request counter is the same as the previous, but no data was sent on the last request (standard situation)
     *
     *    3. request counter is the same as the previous and last time objects were sent (loop!)
     *      3.1)   no loop was detected before, entereing loop mode     -> save loop data, loopcount = 1
     *      3.2)   loop was detected before, but are gone               -> loop resolved
     *      3.3)   loop was detected before, continuing in loop mode    -> this is probably the broken element,loopcount++,
     *      3.3.1) item identified, loopcount >= 3                      -> ignore item, set ignoredata flag
     *
     * @param string $folderid          the current folder id to be worked on
     * @param string $type              the type of that folder (Email, Calendar, Contact, Task)
     * @param string $uuid              the synkkey
     * @param string $counter           the synckey counter
     * @param string $maxItems          the current amount of items to be sent to the mobile
     * @param string $queuedMessages    the amount of messages which were found by the exporter
     *
     * @access public
     * @return boolean      when returning true if a loop has been identified
     */
    public function Detect($folderid, $type, $uuid, $counter, $maxItems, $queuedMessages) {
        $this->broken_message_uuid = $uuid;
        $this->broken_message_counter = $counter;

        // if an incoming loop is already detected, do nothing
        if ($maxItems === 0 && $queuedMessages > 0) {
            ZPush::GetTopCollector()->AnnounceInformation("Incoming loop!", true);
            return true;
        }

        // initialize params
        $this->InitializeParams();

        $loop = false;

        // exclusive block
        if ($this->blockMutex()) {
            $loopdata = ($this->hasData()) ? $this->getData() : array();

            // check and initialize the array structure
            $this->checkArrayStructure($loopdata, $folderid);

            $current = $loopdata[self::$devid][self::$user][$folderid];

            // completely new/unknown UUID
            if (empty($current))
                $current = array("type" => $type, "uuid" => $uuid, "count" => $counter-1, "queued" => $queuedMessages);

            // old UUID in cache - the device requested a new state!!
            else if (isset($current['type']) && $current['type'] == $type && isset($current['uuid']) && $current['uuid'] != $uuid ) {
                ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): UUID changed for folder");

                // some devices (iPhones) may request new UUIDs after broken items were sent several times
                if (isset($current['queued']) && $current['queued'] > 0 &&
                    (isset($current['maxCount']) && $current['count']+1 < $current['maxCount'] || $counter == 1)) {

                    ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): UUID changed and while items where sent to device - forcing loop mode");
                    $loop = true; // force loop mode
                    $current['queued'] = $queuedMessages;
                }
                else {
                    $current['queued'] = 0;
                }

                // set new data, unset old loop information
                $current["uuid"] = $uuid;
                $current['count'] = $counter;
                unset($current['loopcount']);
                unset($current['ignored']);
                unset($current['maxCount']);
                unset($current['potential']);
            }

            // see if there are values
            if (isset($current['uuid']) && $current['uuid'] == $uuid &&
                isset($current['type']) && $current['type'] == $type &&
                isset($current['count'])) {

                // case 1 - standard, during loop-resolving & resolving
                if ($current['count'] < $counter) {

                    // case 1.1
                    $current['count'] = $counter;
                    $current['queued'] = $queuedMessages;

                    // case 1.2
                    if (isset($current['maxCount'])) {
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 1.2 detected");

                        // case 1.2.1
                        // broken item not identified yet
                        if (!isset($current['ignored']) && $counter < $current['maxCount']) {
                            $loop = true; // continue in loop-resolving
                            ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 1.2.1 detected");
                        }
                        // case 1.2.2 - if there were any broken items they should be gone, return to normal
                        else {
                            ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 1.2.2 detected");
                            unset($current['loopcount']);
                            unset($current['ignored']);
                            unset($current['maxCount']);
                            unset($current['potential']);
                        }
                    }
                }

                // case 2 - same counter, but there were no changes before and are there now
                else if ($current['count'] == $counter && $current['queued'] == 0 && $queuedMessages > 0) {
                    $current['queued'] = $queuedMessages;
                }

                // case 3 - same counter, changes sent before, hanging loop and ignoring
                else if ($current['count'] == $counter && $current['queued'] > 0) {

                    if (!isset($current['loopcount'])) {
                        // case 3.1) we have just encountered a loop!
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 3.1 detected - loop detected, init loop mode");
                        $current['loopcount'] = 1;
                        // the MaxCount is the max number of messages exported before
                        $current['maxCount'] = $counter + (($maxItems < $queuedMessages)? $maxItems: $queuedMessages);
                        $loop = true;   // loop mode!!
                    }
                    else if ($queuedMessages == 0) {
                        // case 3.2) there was a loop before but now the changes are GONE
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 3.2 detected - changes gone - clearing loop data");
                        $current['queued'] = 0;
                        unset($current['loopcount']);
                        unset($current['ignored']);
                        unset($current['maxCount']);
                        unset($current['potential']);
                    }
                    else {
                        // case 3.3) still looping the same message! Increase counter
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->Detect(): case 3.3 detected - in loop mode, increase loop counter");
                        $current['loopcount']++;

                        // case 3.3.1 - we got our broken item!
                        if ($current['loopcount'] >= 3 && isset($current['potential'])) {
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->Detect(): case 3.3.1 detected - broken item should be next, attempt to ignore it - id '%s'", $current['potential']));
                            $this->ignore_messageid = $current['potential'];
                        }
                        $current['maxCount'] = $counter + $queuedMessages;
                        $loop = true;   // loop mode!!
                    }
                }

            }
            if (isset($current['loopcount']))
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->Detect(): loop data: loopcount(%d), maxCount(%d), queued(%d), ignored(%s)", $current['loopcount'], $current['maxCount'], $current['queued'], (isset($current['ignored'])?$current['ignored']:'false')));

            // update loop data
            $loopdata[self::$devid][self::$user][$folderid] = $current;
            $ok = $this->setData($loopdata);

            $this->releaseMutex();
        }
        // end exclusive block

        if ($loop == true && $this->ignore_messageid == false) {
            ZPush::GetTopCollector()->AnnounceInformation("Loop detection", true);
        }

        return $loop;
    }

    /**
     * Indicates if the next messages should be ignored (not be sent to the mobile!)
     *
     * @param string  $messageid        (opt) id of the message which is to be exported next
     * @param string  $folderid         (opt) parent id of the message
     * @param boolean $markAsIgnored    (opt) to peek without setting the next message to be
     *                                  ignored, set this value to false
     * @access public
     * @return boolean
     */
    public function IgnoreNextMessage($markAsIgnored = true, $messageid = false, $folderid = false) {
        // as the next message id is not available at all point this method is called, we use different indicators.
        // potentialbroken indicates that we know that the broken message should be exported next,
        // alltho we do not know for sure as it's export message orders can change
        // if the $messageid is available and matches then we are sure and only then really ignore it

        $potentialBroken = false;
        $realBroken = false;
        if (Request::GetCommandCode() == ZPush::COMMAND_SYNC && $this->ignore_messageid !== false)
            $potentialBroken = true;

        if ($messageid !== false && $this->ignore_messageid == $messageid)
            $realBroken = true;

        // this call is just to know what should be happening
        // no further actions necessary
        if ($markAsIgnored === false) {
            return $potentialBroken;
        }

        // we should really do something here

        // first we check if we are in the loop mode, if so,
        // we update the potential broken id message so we loop count the same message

        $changedData = false;
        // exclusive block
        if ($this->blockMutex()) {
            $loopdata = ($this->hasData()) ? $this->getData() : array();

            // check and initialize the array structure
            $this->checkArrayStructure($loopdata, $folderid);

            $current = $loopdata[self::$devid][self::$user][$folderid];

            // we found our broken message!
            if ($realBroken) {
                $this->ignore_messageid = false;
                $current['ignored'] = $messageid;
                $changedData = true;

                // check if this message was broken before - here we know that it still is and remove it from the tracking
                $brokenkey = self::BROKENMSGS ."-". $folderid;
                if (isset($loopdata[self::$devid][self::$user][$brokenkey]) && isset($loopdata[self::$devid][self::$user][$brokenkey][$messageid])) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->IgnoreNextMessage(): previously broken message '%s' is still broken and will not be tracked anymore", $messageid));
                    unset($loopdata[self::$devid][self::$user][$brokenkey][$messageid]);
                }
            }
            // not the broken message yet
            else {
                // update potential id if looping on an item
                if (isset($current['loopcount'])) {
                    $current['potential'] = $messageid;

                    // this message should be the broken one, but is not!!
                    // we should reset the loop count because this is certainly not the broken one
                    if ($potentialBroken) {
                        $current['loopcount'] = 1;
                        ZLog::Write(LOGLEVEL_DEBUG, "LoopDetection->IgnoreNextMessage(): this should be the broken one, but is not! Resetting loop count.");
                    }

                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("LoopDetection->IgnoreNextMessage(): Loop mode, potential broken message id '%s'", $current['potential']));

                    $changedData = true;
                }
            }

            // update loop data
            if ($changedData == true) {
                $loopdata[self::$devid][self::$user][$folderid] = $current;
                $ok = $this->setData($loopdata);
            }

            $this->releaseMutex();
        }
        // end exclusive block

        if ($realBroken)
            ZPush::GetTopCollector()->AnnounceInformation("Broken message ignored", true);

        return $realBroken;
    }

    /**
     * Clears loop detection data
     *
     * @param string    $user           (opt) user which data should be removed - user can not be specified without
     * @param string    $devid          (opt) device id which data to be removed
     *
     * @return boolean
     * @access public
     */
    public function ClearData($user = false, $devid = false) {
        $stat = true;
        $ok = false;

        // exclusive block
        if ($this->blockMutex()) {
            $loopdata = ($this->hasData()) ? $this->getData() : array();

            if ($user == false && $devid == false)
                $loopdata = array();
            elseif ($user == false && $devid != false)
                $loopdata[$devid] = array();
            elseif ($user != false && $devid != false)
                $loopdata[$devid][$user] = array();
            elseif ($user != false && $devid == false) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("Not possible to reset loop detection data for user '%s' without a specifying a device id", $user));
                $stat = false;
            }

            if ($stat)
                $ok = $this->setData($loopdata);

            $this->releaseMutex();
        }
        // end exclusive block

        return $stat && $ok;
    }

    /**
     * Returns loop detection data for a user and device
     *
     * @param string    $user
     * @param string    $devid
     *
     * @return array/boolean    returns false if data not available
     * @access public
     */
    public function GetCachedData($user, $devid) {
        // exclusive block
        if ($this->blockMutex()) {
            $loopdata = ($this->hasData()) ? $this->getData() : array();
            $this->releaseMutex();
        }
        // end exclusive block
        if (isset($loopdata) && isset($loopdata[$devid]) && isset($loopdata[$devid][$user]))
            return $loopdata[$devid][$user];

        return false;
    }

    /**
     * Builds an array structure for the loop detection data
     *
     * @param array $loopdata    reference to the topdata array
     *
     * @access private
     * @return
     */
    private function checkArrayStructure(&$loopdata, $folderid) {
        if (!isset($loopdata) || !is_array($loopdata))
            $loopdata = array();

        if (!isset($loopdata[self::$devid]))
            $loopdata[self::$devid] = array();

        if (!isset($loopdata[self::$devid][self::$user]))
            $loopdata[self::$devid][self::$user] = array();

        if (!isset($loopdata[self::$devid][self::$user][$folderid]))
            $loopdata[self::$devid][self::$user][$folderid] = array();
    }
}

?>