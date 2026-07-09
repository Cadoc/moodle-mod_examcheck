// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Camera-based QR/barcode scanner for marking students.
 *
 * Uses the bundled zxing-wasm decoder (a WebAssembly build of the upstream
 * zxing-cpp library) for live camera scanning on every browser the plugin
 * targets — Chrome, Edge, Firefox, Safari on iOS and macOS, and Chromium-based
 * mobile browsers. A manual entry box is always available too and works with
 * USB / Bluetooth "keyboard wedge" scanners or by typing the value.
 *
 * @module     mod_examcheck/scanner
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Url from 'core/url';
import Templates from 'core/templates';
import {add as addToast} from 'core/toast';
import {getString} from 'core/str';
import ZXingWASM from 'mod_examcheck/zxingwasm';
import ConfirmModal from "./confirm_modal";
import InfoModal from "./info_modal";

const DEDUPE_MS = 2500;
const CAMERA_STORAGE_KEY = 'examcheck_scanner_camera';

// How often the decode loop hands a fresh frame to zxing-wasm. ~10 fps is fast
// enough to feel instant on a card scan and leaves plenty of CPU for the rest
// of the page (the decoder typically runs in 20–80 ms per frame on a phone).
const DECODE_INTERVAL_MS = 100;

// Maximum frame dimension fed to the decoder. The video may be 1920×1080 to
// help small/distant codes, but a 1280-wide ImageData decodes ~2× faster and
// is still ample for a printed student card. zxing-cpp also tries internal
// downscales (tryDownscale below), so this is just a sane upper bound.
const DECODE_MAX_DIMENSION = 1280;

// Symbologies we ask the decoder to look for. Covers every QR variant plus the
// common 2D and 1D codes printed on student / library cards. zxing-wasm
// supports more (Telepen, DXFilmEdge, GS1 DataBar Stacked, …) but enabling
// rarely-used formats just slows the decoder down without changing outcomes.
const FORMATS_2D = ['QRCode', 'MicroQRCode', 'RMQRCode', 'DataMatrix', 'Aztec', 'PDF417', 'MaxiCode'];
const FORMATS_1D = [
    'Code128', 'Code39', 'Code93', 'Codabar', 'ITF', 'ITF14',
    'EAN13', 'EAN8', 'UPCA', 'UPCE',
    'DataBar', 'DataBarExp',
];
const FORMATS_ALL = [...FORMATS_2D, ...FORMATS_1D];

const READER_OPTIONS = {
    tryHarder: true,
    tryRotate: true,
    tryInvert: true,
    tryDownscale: true,
    maxNumberOfSymbols: 1,
};

/**
 * Resolve the "Code type" session control's value to a formats list.
 *
 * @param {String} value One of 'all', '2d', '1d'.
 * @returns {String[]} The zxing-wasm format names to look for.
 */
const formatsForCodeType = (value) => {
    if (value === '2d') {
        return FORMATS_2D;
    }
    if (value === '1d') {
        return FORMATS_1D;
    }
    return FORMATS_ALL;
};

// Resolve the zxing-wasm library across module-interop shapes, falling back to the
// global the vendored IIFE also sets. Returns the object exposing readBarcodes, or null.
const zxinglib = (() => {
    const candidates = [ZXingWASM, ZXingWASM && ZXingWASM.default, window.ZXingWASM];
    return candidates.find((c) => c && typeof c.readBarcodes === 'function') || null;
})();

// Resolve the URL of the sibling WebAssembly binary. The plugin ships it at
// /mod/examcheck/wasm/zxing_reader.wasm so the web server serves it as a
// regular static file (with the application/wasm MIME type).
const wasmUrl = (() => {
    const root = (window.M && window.M.cfg && window.M.cfg.wwwroot) ? window.M.cfg.wwwroot : '';
    return root + '/mod/examcheck/wasm/zxing_reader.wasm';
})();

let config = {cmid: 0, groupid: 0};
let root = null;
let mediaStream = null; // The currently open MediaStream, or null when the camera is off.
let rafHandle = 0; // Active requestAnimationFrame handle for the decode loop.
let lastDecodeTime = 0; // Throttle marker for DECODE_INTERVAL_MS.
let decodeBusy = false; // True while a readBarcodes call is in flight.
let decodeCanvas = null; // Reusable canvas for frame capture.
let decodeCtx = null;
let zxingConfigured = false; // True once setZXingModuleOverrides has run.
let scanning = false;
let lastValue = '';
let lastValueTime = 0;
let showCameraSwitcher = false;
let selectedDeviceId = null; // Preferred camera deviceId, or null for the default (rear).
let allowedFormats = FORMATS_ALL; // Symbologies the decode loop currently looks for.

/**
 * @typedef {Object} Outcome
 * @property {String} status
 * @property {String} message
 * @property {Number} stepid
 * @property {Number} userid
 * @property {String} userlabel
 * @property {String} userpicture
 * @property {Number} checkedby
 * @property {String} checkedbyname
 * @property {Number} timecreated
 * @property {String} ago
 * @property {Array<{name: String, checked: Boolean, current: Boolean}>} steps
 */

/**
 * Initialise the scanner page.
 *
 * @param {Number} cmid Course module id.
 * @param {Number} groupid Group context.
 * @param {Boolean} showcameraswitcher Whether to offer the manual camera picker.
 */
export const init = (cmid, groupid, showcameraswitcher) => {
    root = document.querySelector('[data-region="examcheck-scanner"]');
    if (!root) {
        return;
    }
    config = {cmid, groupid};
    showCameraSwitcher = Boolean(showcameraswitcher);
    try {
        selectedDeviceId = window.localStorage.getItem(CAMERA_STORAGE_KEY) || null;
    } catch (e) {
        selectedDeviceId = null;
    }

    registerControls();
    detectFeatureSupport();
};

/**
 * Wire up the buttons and manual entry on the page.
 */
const registerControls = () => {
    root.querySelector('[data-action="startcamera"]')?.addEventListener('click', startCamera);
    root.querySelector('[data-action="stopcamera"]')?.addEventListener('click', stopCamera);
    root.querySelector('[data-action="next"]')?.addEventListener('click', resumeScanning);
    root.querySelector('[data-action="cancel"]')?.addEventListener('click', resumeScanning);
    root.querySelector('[data-region="cameraselect"]')?.addEventListener('change', (e) => switchCamera(e.target.value));
    root.querySelector('[data-region="codetype"]')?.addEventListener('change', (e) => {
        allowedFormats = formatsForCodeType(e.target.value);
    });
    root.querySelector('[data-region="scannermode"]')?.addEventListener('change', applyModeVisibility);
    applyModeVisibility();

    const form = root.querySelector('[data-region="manualform"]');
    form?.addEventListener('submit', (e) => {
        e.preventDefault();
        const input = root.querySelector('[data-region="manualvalue"]');
        const value = input ? input.value : '';
        if (value.trim() !== '') {
            process(value);
            if (input) {
                input.value = '';
                input.focus();
            }
        }
    });
};

/**
 * Decide whether live camera scanning is possible and adjust the UI.
 *
 * Needs a camera (getUserMedia, which requires a secure/HTTPS context) and the
 * bundled zxing-wasm decoder. zxing-wasm is a WebAssembly build of zxing-cpp;
 * it reads QR codes and common 1D/2D barcodes from the camera, so the same
 * scanning path works on every browser the plugin targets.
 */
const detectFeatureSupport = () => {
    const hascamera = Boolean(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    if (!hascamera || !zxinglib) {
        toggle('[data-region="camerawrap"]', false);
        showStatus('cameraunsupported', 'info');
    }
};

/**
 * Build the getUserMedia constraints: a high ideal resolution (so small/distant codes
 * decode, especially on desktop webcams) for either the chosen camera or the rear one.
 *
 * @returns {Object} MediaStreamConstraints.
 */
const buildConstraints = () => {
    const video = {width: {ideal: 1920}, height: {ideal: 1080}};
    if (selectedDeviceId) {
        video.deviceId = {exact: selectedDeviceId};
    } else {
        video.facingMode = {ideal: 'environment'};
    }
    return {audio: false, video};
};

/**
 * Tell zxing-wasm where to fetch the .wasm binary from. Called once, lazily,
 * the first time the camera is started so the WebAssembly download doesn't
 * happen on pages that never use the scanner.
 */
const configureDecoder = () => {
    if (zxingConfigured || !zxinglib) {
        return;
    }
    zxinglib.setZXingModuleOverrides({
        locateFile: (path, prefix) => (path.endsWith('.wasm') ? wasmUrl : prefix + path),
    });
    zxingConfigured = true;
};

/**
 * Open the camera, attach it to the video element, and start playing.
 *
 * @param {HTMLVideoElement} video The live video element.
 * @returns {Promise} Resolves once the stream is playing.
 */
const openStream = async(video) => {
    const stream = await navigator.mediaDevices.getUserMedia(buildConstraints());
    mediaStream = stream;
    video.srcObject = stream;
    // Play() can reject on some browsers if the tab loses focus mid-start; we
    // already gate startCamera behind a user-gesture click so this is rare.
    await video.play();
};

/**
 * Stop the active stream's tracks (releases the camera light) and drop refs.
 */
const stopStream = () => {
    if (mediaStream) {
        mediaStream.getTracks().forEach((t) => t.stop());
        mediaStream = null;
    }
};

/**
 * Report that the camera could not be started.
 */
const failStart = () => {
    cancelDecodeLoop();
    stopStream();
    showStatus('camerablocked', 'warning');
};

/**
 * Start the camera and the continuous zxing-wasm decode loop, then offer the camera picker.
 */
const startCamera = async() => {
    if (!zxinglib) {
        return;
    }
    configureDecoder();
    const video = root.querySelector('[data-region="video"]');
    try {
        await openStream(video);
    } catch (e) {
        // A remembered camera may no longer exist on this device: drop it and retry.
        if (selectedDeviceId) {
            selectedDeviceId = null;
            try {
                await openStream(video);
            } catch (retryerror) {
                failStart();
                return;
            }
        } else {
            failStart();
            return;
        }
    }

    toggle('[data-action="startcamera"]', false);
    toggle('[data-action="stopcamera"]', true);
    resumeScanning();
    populateCameras(video);
    startDecodeLoop(video);
};

/**
 * Populate and reveal the camera picker (when enabled and more than one camera exists).
 *
 * @param {HTMLVideoElement} video The live video element.
 */
const populateCameras = async(video) => {
    if (!showCameraSwitcher) {
        return;
    }
    const wrap = root.querySelector('[data-region="cameraselectwrap"]');
    const select = root.querySelector('[data-region="cameraselect"]');
    if (!wrap || !select) {
        return;
    }

    let cameras;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        cameras = devices.filter((d) => d.kind === 'videoinput');
    } catch (e) {
        return;
    }
    if (cameras.length < 2) {
        return;
    }

    const track = video.srcObject && video.srcObject.getVideoTracks ? video.srcObject.getVideoTracks()[0] : null;
    const current = (track && track.getSettings) ? (track.getSettings().deviceId || '') : '';

    // Pre-fetch the "Camera" label once if at least one device has no label
    // (common on iOS until camera permission has been granted at least once).
    const needFallback = cameras.some((c) => !c.label);
    const cameraWord = needFallback ? await getString('camera', 'mod_examcheck') : '';

    select.innerHTML = '';
    cameras.forEach((camera, index) => {
        const option = document.createElement('option');
        option.value = camera.deviceId;
        option.textContent = camera.label || `${cameraWord} ${index + 1}`;
        if (camera.deviceId && camera.deviceId === current) {
            option.selected = true;
        }
        select.appendChild(option);
    });
    wrap.classList.remove('d-none');
};

/**
 * Switch to a specific camera and restart decoding.
 *
 * @param {String} deviceId The chosen camera deviceId.
 */
const switchCamera = async(deviceId) => {
    if (!deviceId || !zxinglib) {
        return;
    }
    selectedDeviceId = deviceId;
    try {
        window.localStorage.setItem(CAMERA_STORAGE_KEY, deviceId);
    } catch (e) {
        // Storage unavailable (private mode); the choice just won't persist.
    }

    scanning = false;
    cancelDecodeLoop();
    stopStream();
    const video = root.querySelector('[data-region="video"]');
    try {
        await openStream(video);
        resumeScanning();
        startDecodeLoop(video);
    } catch (e) {
        failStart();
    }
};

/**
 * Stop the camera and the decode loop.
 */
const stopCamera = () => {
    scanning = false;
    cancelDecodeLoop();
    stopStream();
    const video = root.querySelector('[data-region="video"]');
    if (video) {
        video.srcObject = null;
    }
    toggle('[data-region="cameraselectwrap"]', false);
    toggle('[data-action="startcamera"]', true);
    toggle('[data-action="stopcamera"]', false);
};

/**
 * Grab the current video frame as ImageData, downscaling if it exceeds
 * DECODE_MAX_DIMENSION on its longest side.
 *
 * @param {HTMLVideoElement} video The live video element.
 * @returns {ImageData} The captured frame.
 */
const grabFrame = (video) => {
    const vw = video.videoWidth;
    const vh = video.videoHeight;
    let w = vw;
    let h = vh;
    const longest = Math.max(vw, vh);
    if (longest > DECODE_MAX_DIMENSION) {
        const scale = DECODE_MAX_DIMENSION / longest;
        w = Math.round(vw * scale);
        h = Math.round(vh * scale);
    }
    if (!decodeCanvas) {
        decodeCanvas = document.createElement('canvas');
        decodeCtx = decodeCanvas.getContext('2d', {willReadFrequently: true});
    }
    if (decodeCanvas.width !== w) {
        decodeCanvas.width = w;
    }
    if (decodeCanvas.height !== h) {
        decodeCanvas.height = h;
    }
    decodeCtx.drawImage(video, 0, 0, w, h);
    return decodeCtx.getImageData(0, 0, w, h);
};

/**
 * Run the decode loop on the given video element. Self-throttled to
 * DECODE_INTERVAL_MS so a single slow decode doesn't pile up frames.
 *
 * @param {HTMLVideoElement} video The live video element.
 */
const startDecodeLoop = (video) => {
    cancelDecodeLoop();
    const tick = async() => {
        // Camera was stopped: end the loop.
        if (!mediaStream) {
            rafHandle = 0;
            return;
        }
        rafHandle = requestAnimationFrame(tick);
        // Paused (post-mark, awaiting confirm, or in-flight AJAX): skip decode.
        if (!scanning || decodeBusy) {
            return;
        }
        const now = performance.now();
        if (now - lastDecodeTime < DECODE_INTERVAL_MS) {
            return;
        }
        // Video not yet producing frames.
        if (video.readyState < 2 || !video.videoWidth) {
            return;
        }
        lastDecodeTime = now;
        decodeBusy = true;
        try {
            const imageData = grabFrame(video);
            // The code type picker is hidden in reading mode, so it looks for every format.
            const formats = currentMode() === 'reading' ? FORMATS_ALL : allowedFormats;
            const results = await zxinglib.readBarcodes(imageData, {...READER_OPTIONS, formats});
            if (results && results.length && scanning) {
                process(results[0].text);
            }
        } catch (e) {
            // Transient decoder errors (eg. first-call WASM fetch failure): skip frame.
        } finally {
            decodeBusy = false;
        }
    };
    rafHandle = requestAnimationFrame(tick);
};

/**
 * Cancel the running decode loop.
 */
const cancelDecodeLoop = () => {
    if (rafHandle) {
        cancelAnimationFrame(rafHandle);
        rafHandle = 0;
    }
    decodeBusy = false;
};

/**
 * Process a scanned or typed value.
 *
 * @param {String} value The raw value.
 */
const process = (value) => {
    const now = Date.now();
    // Ignore the same value scanned repeatedly in quick succession.
    if (value === lastValue && (now - lastValueTime) < DEDUPE_MS) {
        return;
    }
    lastValue = value;
    lastValueTime = now;

    const mode = currentMode();
    // Reading mode never marks, so the confirm step doesn't apply.
    const requireConfirm = mode === 'scanning' && isConfirmRequired();
    scanning = false; // Pause while we resolve this value.

    Ajax.call([{
        methodname: 'mod_examcheck_scan_lookup',
        args: {
            cmid: config.cmid,
            stepid: currentStep(),
            scanfield: currentField(),
            value: value,
            confirm: false,
            requireconfirm: requireConfirm,
            groupid: config.groupid,
            mode: mode,
        },
    }])[0].then((outcome) => {
        handleOutcome(outcome, value);
        return outcome;
    }).catch((err) => {
        addToast(err.message || String(err), {type: 'danger'});
        resumeScanning();
    });
};

/**
 * Build the "View in roster" link for a scan outcome: the roster (view.php) focused
 * on the matched student via their userid, so the modal link lands on just this student.
 *
 * @param {Outcome} outcome The scan-lookup outcome (carries userid).
 * @returns {String} A roster URL, filtered to the student when a userid is present.
 */
const rosterLinkFor = (outcome) => {
    const params = {id: config.cmid};
    if (outcome.userid) {
        params.userid = outcome.userid;
    }
    return Url.relativeUrl('/mod/examcheck/view.php', params);
};

/**
 * Show the "already checked" conflict as a warning toast, carrying the server message
 * (which already includes the scanned value) plus a "View in roster" link. The toast
 * auto-hides after a longer-than-default delay so the link is clickable for a while.
 *
 * @param {Outcome} outcome The scan-lookup outcome.
 * @returns {Promise<void>}
 */
const showConflictToast = async(outcome) => {
    const {html} = await Templates.renderForPromise('mod_examcheck/conflict_toast', {
        message: outcome.message,
        rosterlink: rosterLinkFor(outcome),
    });
    addToast(html, {type: 'warning', delay: 8000});
};

/**
 * Reading mode: show the student's name and a read-only per-step status table.
 *
 * @param {Outcome} outcome
 * @param {String} scannedValue
 * @returns {Promise<void>}
 */
const showInfoModal = async(outcome, scannedValue) => {
    pauseScanning();

    const modal = await InfoModal.create({
        templateContext: {
            userFullname: outcome.userlabel,
            userPicture: outcome.userpicture,
            scanFieldName: currentFieldName(),
            scanValue: scannedValue,
            rosterLink: rosterLinkFor(outcome),
            steps: outcome.steps
        },
    });

    await modal.show();
    resumeScanning();
};

/**
 * Show the confirmation modal and mark the check step if confirmed.
 *
 * @param {Outcome} outcome
 * @param {String} scannedValue
 * @returns {Promise<void>}
 */
const showConfirmationModal = async(outcome, scannedValue) => {
    pauseScanning();

    /** @var {ConfirmModal} modal */
    const modal = await ConfirmModal.create({
        templateContext: {
            stepName: currentStepName(),
            userFullname: outcome.userlabel,
            userPicture: outcome.userpicture,
            scanFieldName: currentFieldName(),
            scanValue: scannedValue,
            rosterLink: rosterLinkFor(outcome),
            steps: outcome.steps
        },
    });

    modal.show();

    const confirmed = await modal.wasConfirmed();
    if (!confirmed) {
        resumeScanning();
        return;
    }

    await Ajax.call([{
        methodname: 'mod_examcheck_scan_lookup',
        args: {
            cmid: config.cmid,
            stepid: currentStep(),
            scanfield: currentField(),
            value: scannedValue,
            confirm: true,
            requireconfirm: true,
            groupid: config.groupid,
        },
    }])[0]
        .then((outcome) => {
            if (outcome.status === 'marked') {
                addToast(outcome.message, {type: 'success'});
            } else if (outcome.status === 'conflict') {
                showConflictToast(outcome);
            } else if (outcome.status === 'requirementnotmet') {
                // Defensive: scan() fails fast before needsconfirm, so we should never get
                // here for the gate — but if a step is reconfigured mid-session it could.
                addToast(outcome.message, {type: 'danger'});
            } else {
                addToast(outcome.message, {type: 'info'});
            }
            // In confirm mode we always wait for an explicit "scan next".
            resumeScanning();
            return outcome;
        })
        .catch((err) => {
            addToast(err.message || String(err), {type: 'danger'});
            resumeScanning();
        });
};

/**
 * Act on the result of a scan lookup.
 *
 * @param {Object} outcome The web service outcome.
 * @param {String} value The scanned value (kept for the confirm step).
 */
const handleOutcome = (outcome, value) => {
    switch (outcome.status) {
        case 'found':
            // Reading mode: name + per-step check status, no marking.
            showInfoModal(outcome, value);
            break;
        case 'needsconfirm':
            showConfirmationModal(outcome, value);
            break;
        case 'marked':
            addToast(outcome.message, {type: 'success'});
            resumeScanning();
            break;
        case 'conflict':
            // Already checked: a non-blocking warning toast (with the scanned value and
            // a roster link), not a modal, so scanning can carry on.
            showConflictToast(outcome);
            resumeScanning();
            break;
        case 'notfound':
            // The result_notfound lang string embeds the scanned value so a mis-scan is obvious.
            addToast(outcome.message, {type: 'warning'});
            resumeScanning();
            break;
        case 'notenrolled':
            // A real account matched the scanned value, but it isn't enrolled in this course.
            addToast(outcome.message, {type: 'warning'});
            resumeScanning();
            break;
        case 'requirementnotmet':
            // Step's requirement gate refused: stay in scanning mode, don't mark.
            addToast(outcome.message, {type: 'danger'});
            resumeScanning();
            break;
        default:
            addToast(outcome.message, {type: 'info'});
            resumeScanning();
    }
};

const resumeScanning = () => {
    scanning = Boolean(mediaStream); // Only auto-scan when the camera is running.
};

const pauseScanning = () => {
    scanning = false;
};

/**
 * Display a result message in the result panel.
 *
 * @param {String} message The message text.
 * @param {String} type The bootstrap alert type.
 */
const showMessage = (message, type) => {
    const region = root.querySelector('[data-region="result"]');
    if (!region) {
        return;
    }
    region.className = `alert alert-${type} examcheck-result`;
    region.textContent = message;
    region.classList.remove('d-none');
};

/**
 * Display a translated status message.
 *
 * @param {String} key The language string key.
 * @param {String} type The bootstrap alert type.
 */
const showStatus = (key, type) => {
    getString(key, 'mod_examcheck').then((s) => {
        showMessage(s, type);
        return s;
    }).catch(() => {
        showMessage(key, type);
    });
};

/**
 * Toggle the visibility of an element.
 *
 * @param {String} selector The element selector within the root.
 * @param {Boolean} visible Whether it should be visible.
 */
const toggle = (selector, visible) => {
    const el = root.querySelector(selector);
    if (el) {
        el.classList.toggle('d-none', !visible);
    }
};

/**
 * @returns {Number} The currently selected step id.
 */
const currentStep = () => parseInt(root.querySelector('[data-region="step"]').value, 10);

/**
 * @returns {String} The currently selected step name.
 */
const currentStepName = () => root.querySelector('[data-region="step"]').selectedOptions[0].text;


/**
 * @returns {String} The currently selected scan field key.
 */
const currentField = () => root.querySelector('[data-region="scanfield"]').value;

/**
 * @returns {String} The currently selected scan field name.
 */
const currentFieldName = () => root.querySelector('[data-region="scanfield"]').selectedOptions[0].text;

/**
 * @returns {String} The current scanner mode, "scanning" or "reading".
 */
const currentMode = () => {
    const el = root.querySelector('[data-region="scannermode"]');
    return el ? el.value : 'scanning';
};

/**
 * Show the step / code type / confirm controls only in "scanning" mode.
 */
const applyModeVisibility = () => {
    toggle('[data-region="scanningonly"]', currentMode() !== 'reading');
};

/**
 * @returns {Boolean} Whether the session requires confirmation before marking.
 */
const isConfirmRequired = () => {
    const el = root.querySelector('[data-action="requireconfirm"]');
    return el ? el.checked : false;
};
