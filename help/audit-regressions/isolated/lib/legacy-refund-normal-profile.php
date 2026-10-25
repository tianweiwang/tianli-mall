<?php
declare(strict_types=1);

/** Fixed normal profile only; importing starts no App, process, test or resource. */
function audit_app_stop_legacy_refund_normal_group(&$process, ?array &$group, array $supervisor, bool &$termSent): array
{
    audit_assert_legacy_refund_private_mount();
    if ($group === null && is_resource($process) && proc_get_status($process)['running']) {
        $group = audit_app_register_group($process, $supervisor);
    }
    $stopped = $group; $remaining = [];
    if ($group !== null) {
        audit_expect(is_resource($process) && proc_get_status($process)['pid'] === $group['leader_pid'],
            'normal stop requires the original registered child handle');
        $remaining = audit_app_group_members($group, $supervisor);
        if ($remaining !== [] && !$termSent) {
            // Mark before the native call: an unknown signal result must not be retried.
            $termSent = true;
            if (!posix_kill(-$group['pgid'], SIGTERM)) {
                audit_expect(audit_app_group_members($group, $supervisor) === [], 'normal TERM signal failed; state retained');
            }
        }
        $deadline = microtime(true) + 3.0;
        do {
            if (is_resource($process)) { proc_get_status($process); }
            while (pcntl_waitpid(-$group['pgid'], $waitStatus, WNOHANG) > 0) {}
            $remaining = audit_app_group_members($group, $supervisor);
            if ($remaining === [] && !audit_app_listener_open()) { break; }
            usleep(20000);
        } while (microtime(true) < $deadline);
    }
    audit_expect($remaining === [] && !audit_app_listener_open()
        && (!is_resource($process) || !proc_get_status($process)['running']),
        'normal TERM observation incomplete; original state retained without escalation');
    if (is_resource($process)) { proc_close($process); $process = null; }
    $group = null;
    return ['stopped_group' => $stopped, 'remaining_members' => [], 'members' => [], 'listener_closed' => true, 'listener_open' => false,
        'stop_mode' => 'term-only', 'term_sent' => $termSent, 'force_signal_sent' => false];
}

function audit_assert_legacy_refund_private_mount():void
{
    $manifest=audit_manifest();$project=$manifest['project']??'';
    audit_expect(getenv('AUDIT_LEGACY_REFUND_NORMAL')==='1'&&($manifest['guard_mode']??null)==='read-only'
        &&$project==='tianli-audit-'.getenv('AUDIT_RUN_ID'),'normal legacy-refund profile identity rejected');
    $volume=$project.'_customer-refund-private';
    audit_expect(in_array($volume,$manifest['volumes']??[],true)&&isset($manifest['volume_identities'][$volume]),
        'normal legacy-refund private volume must have a registered native identity');
    $found=[];
    foreach(file(audit_run_dir().'/containers.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)as$line){
        $item=json_decode($line,true,512,JSON_THROW_ON_ERROR);
        if(($item['id']??null)!==($manifest['services']['audit-app']['id']??null))continue;
        audit_expect(($item['labels']['com.docker.compose.project']??null)===$project
            &&($item['labels']['tianli.audit.run']??null)===getenv('AUDIT_RUN_ID')
            &&($item['read_only']??null)===true&&($item['privileged']??null)===false,'normal legacy-refund actual application rejected');
        foreach($item['mounts']??[]as$mount)if(($mount['Destination']??null)==='/run/audit-customer-refund')$found[]=$mount;
    }
    audit_expect(count($found)===1&&($found[0]['Type']??null)==='volume'&&($found[0]['Name']??null)===$volume
        &&($found[0]['RW']??null)===true,'normal legacy-refund actual private mount rejected');
}
