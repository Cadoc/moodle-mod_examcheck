ZXing-js (@zxing/library) — bundled barcode/QR decoder for the scanner fallback.

Upstream: https://github.com/zxing-js/library  (npm: @zxing/library)
Version:  0.21.3
License:  MIT

amd/src/zxing.js is the upstream UMD browser build
(@zxing/library@0.21.3/umd/index.min.js) with two local changes:

1. The AMD branch of the UMD wrapper ("function"==typeof define&&define.amd ...)
   was removed so the file does not call define() — it always populates the
   global ZXing object instead. This avoids a "Mismatched anonymous define"
   error when Moodle's grunt/rollup wraps it as an AMD module.
2. A trailing "export default window.ZXing;" was appended so a sibling AMD
   module can import it (e.g. import ZXing from 'mod_examcheck/zxing').

It is declared in ../../thirdpartylibs.xml so grunt excludes it from ESLint;
grunt amd still compiles it to amd/build/zxing.min.js.

To upgrade: download the matching umd/index.min.js, re-apply the two changes
above, bump the version here and in thirdpartylibs.xml, and run grunt amd.
