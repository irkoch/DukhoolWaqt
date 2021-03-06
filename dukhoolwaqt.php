<?php
  // $Id$

  /*
   Copyright 2011 Ameen Ross, <http://www.dukhoolwaqt.org>

   This program is free software: you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   (at your option) any later version.

   This program is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.

   You should have received a copy of the GNU General Public License
   along with this program.  If not, see <http://www.gnu.org/licenses/>.
  */


  /**
   * @file
   * A class library to calculate Islamic prayer times, qibla and more.
   */

/**
 * Implementation of a prayer time calculation algorithm.
 */
class DukhoolWaqt {

  // Constants ================================================================

  // Ka'aba geographical coordinates
  const kaabaLat = 21.422517;
  const kaabaLng = 39.826166;

  // Default location coordinates & timezone (Masjid An-Nabawi)
  const defaultLat = 24.494647;
  const defaultLng = 39.770508;
  const defaultZone = 3; // GMT + 3

  // The sun's altitude at sunrise and sunset
  const sunset = -0.8333;

  // Extreme latitudes adjustment constants
  const overhead = 0.05;
  const latitudeUpper = 0;
  const errorMargin = .1;

  // Used to compute sidereal time
  const earthSrt1 = 6.697374558;
  const earthSrt2 = 0.06570982441908;
  const earthSrt3 = 1.002737909350795;
  const earthSrt4 = 0.000026;

  // Used to compute obliquity of the ecliptic
  const earthObl1 = 0.40909;
  const earthObl2 = -0.0002295;

  // Used to compute the sun's mean longitude, anomaly and equation of center
  const sunLng1 = 4.89506;
  const sunLng2 = 628.33197;
  const sunAno1 = 6.24006;
  const sunAno2 = 628.30195;
  const sunCenter1 = 0.03342;
  const sunCenter2 = -0.0000873;
  const sunCenter3 = 0.000349;

  // Epochs in Julian date (difference between UTC and TT is assumed constant)
  const unixEpochJD = 2440587.500761306; // Unix epoch (1970/1/1 0:00 UTC)
  const J2000EpochJD = 2451545; // J2000 epoch (2000/1/1 12:00 TT)

  // Misc constants
  const defaultAccuracy = 2;

  // Variables (to set) =======================================================

  private $longitude = NULL;
  private $latitude = NULL;
  private $timeZone = NULL;
  private $time = NULL;

  private $calcSettings = array(
    'angle' => array(
      'fajr' => NULL,
      'asr' => NULL, // A factor rather than an angle, here for convenience
      'isha' => NULL,
    ),
    'ishaMinutes' => NULL,
    'adjustMins' => array(
      'fajr' => NULL,
      'shurooq' => NULL,
      'dhohr' => NULL,
      'asr' => NULL,
      'maghrib' => NULL,
      'isha' => NULL,
    ),
  );

  private $methodID = NULL;

  // Quasi constants
  private $methodName = array('Karachi', 'ISNA', 'MWL', 'Makkah', 'Egypt');
  private $asrMethodName = array('Shafii', 'Hanafi');

  // Constructor ==============================================================

  function __construct($lat = NULL, $lng = NULL, $zone = NULL, $method = NULL,
$asrMethod = NULL, $adjustMins = array(NULL, NULL, NULL, NULL, NULL, NULL)) {
    $this->setLocation($lat, $lng);
    $this->setTimeZone($zone);
    $this->setMethod($method);
    $this->setAsrMethod($asrMethod);
    $this->setAdjustMins($adjustMins);
  }

  // Set functions ============================================================

  function setLocation($lat = NULL, $lng = NULL) {
    if (is_numeric($lat) && is_numeric($lng) &&
$lat < 90 && $lat > -90 && $lng < 180 && $lng >= -180) {
      $this->latitude = $lat;
      $this->longitude = $lng;
    }
    else {
      $this->latitude = self::defaultLat;
      $this->longitude = self::defaultLng;
    }
  }

  function setTimeZone($zone = NULL) {
    if ($zone <= 15 && $zone >= -13) {
      $this->timeZone = $zone;
    }
    else {
      $this->timeZone = self::defaultZone;
    }
  }

  function setMethod($method = NULL) {

    if (is_null($method)) {
      $methodID = 0;
    }
    elseif (is_string($method)) {
      for ($i = 0; $i < 5; $i++) {
        if (strcasecmp($method, $this->methodName[$i]) == 0) {
          $methodID = $i;
          break;
        }
      }
      !isset($methodID) ? $methodID = 0 : NULL;
    }
    elseif (is_int($method) && $method >= 0 && $method < 5) {
      $methodID = $method;
    }
    else {
      $methodID = 0;
    }

    $this->methodID = $methodID;

    $settings =& $this->calcSettings; // Reference class calcSettings array
    switch ($methodID) {
      case 0: // Karachi
        $settings['angle']['fajr'] = -18;
        $settings['angle']['isha'] = -18;
        $settings['ishaMinutes'] = 0;
        break;
      case 1: // ISNA (Islamic Society of North America)
        $settings['angle']['fajr'] = -15;
        $settings['angle']['isha'] = -15;
        $settings['ishaMinutes'] = 0;
        break;
      case 2: // MWL (Muslim World League)
        $settings['angle']['fajr'] = -18;
        $settings['angle']['isha'] = -17;
        $settings['ishaMinutes'] = 0;
        break;
      case 3: // Makkah (Umm al Quraa)
        $settings['angle']['fajr'] = -19;
        $settings['angle']['isha'] = self::sunset;
        $settings['ishaMinutes'] = 90;
        break;
      case 4: // Egypt
        $settings['angle']['fajr'] = -19.5;
        $settings['angle']['isha'] = -17.5;
        $settings['ishaMinutes'] = 0;
        break;
    }
  }

  function setAsrMethod($asrMethod = NULL) {
    if (is_null($asrMethod)) {
      $asrMethodID = 0;
    }
    elseif (is_string($asrMethod)) {
      for ($i = 0; $i < 2; $i++) {
        if (strcasecmp($asrMethod, $this->asrMethodName[$i]) == 0) {
          $asrMethodID = $i;
          break;
        }
      }
      !isset($asrMethodID) ? $asrMethodID = 0 : NULL;
    }
    elseif (is_int($asrMethod) && $asrMethod >= 0 && $asrMethod < 2) {
      $asrMethodID = $asrMethod;
    }
    else {
      $asrMethodID = 0;
    }

    $this->calcSettings['angle']['asr'] = $asrMethodID;
  }

  function setAdjustMins($adjustMins = array(NULL, NULL, NULL, NULL, NULL,
NULL)) {
    if (is_array($adjustMins)) {
      $setDefault = FALSE;
      for ($i = 0; $i < 6; $i++) {
        if (!array_key_exists($i, $adjustMins)) {
          $setDefault = TRUE;
          break;
        }
      }
      if (!$setDefault) {
        for ($i = 0; $i < 6; $i++) {
          if (!is_numeric($adjustMins[$i])) {
            $setDefault = TRUE;
            break;
          }
        }
      }
    }
    else {
      $setDefault = TRUE;
    }

    if ($setDefault) {
      $adjustMins = array(0, 0, 0, 0, 0, 0);
    }

    $settings =& $this->calcSettings['adjustMins']; // Makes below line cleaner
    $settings = array_combine(array_keys($settings), $adjustMins);
  }

  // Get functions (basic) ====================================================

  function getLocation() {
    return array($this->latitude, $this->longitude);
  }

  function getLatitude() {
    return $this->latitude;
  }

  function getLongitude() {
    return $this->longitude;
  }

  function getTimeZone() {
    return $this->timeZone;
  }

  function getMethod() {
    return $this->methodName[$this->methodID];
  }

  function getMethodID() {
    return $this->methodID;
  }

  function getAsrMethod() {
    return $this->asrMethodName[$this->calcSettings['angle']['asr']];
  }

  function getAsrMethodID() {
    return $this->calcSettings['angle']['asr'];
  }

  function getAdjustMins() {
    return array_values($this->calcSettings['adjustMins']);
  }

  // Get functions (real deal) ================================================

  function getQibla() {
    return $this->qiblaAzimuth();
  }

  function getSunAzimuth($time = NULL) {
    !is_int($time) ? $time = time() : NULL;
    return $this->sunAzimuth($time);
  }

  function getMoonAzimuth($time = NULL) {
    !is_int($time) ? $time = time() : NULL;
    return $this->moonAzimuth($time, self::defaultAccuracy);
  }

  function getTimes($time = NULL) {
    !is_int($time) ? $time = time() : NULL;
    $basetime = $this->basetime($time);
    $midnight = $this->midnight($basetime);
    $midnight1 = $this->midnight($basetime + 86400);
    $dhohr = $this->dhohr($basetime);
    $latitude = $this->latitude;

    $upper = self::latitudeUpper;
    $lower = min($this->calcSettings['angle']);
    $diff = $upper - $lower;
    $upper += $diff * self::overhead;
    $lower -= $diff * self::overhead;

    $latitude = $this->latitude;
    $fallback = FALSE;
    $nightLat = $this->sunAltitude($midnight);
    $dayLat = $this->sunAltitude($dhohr);
    if ($nightLat > $lower || $dayLat < $upper) {
      $adjust = max($nightLat - $lower, $upper - $dayLat);
      if (abs($latitude) < $adjust) {
        $fallback = TRUE;
      }
      else {
        $adjust *= ($latitude > 0) ? 1 : -1;
        $this->latitude -= $adjust;
        $fallback = ($this->sunAltitude($midnight) - $lower > self::errorMargin
|| $upper - $this->sunAltitude($dhohr) > self::errorMargin);
      }
    }

    if ($fallback) {
      $times = array(
        $basetime,
        $dhohr - (90 - $this->calcSettings['angle']['fajr']) * 240,
        $dhohr - (90 - self::sunset) * 240,
        $dhohr,
        $dhohr + atan($this->calcSettings['angle']['asr'] + 1) / M_PI * 43200,
        $dhohr + (90 - self::sunset) * 240,
        $dhohr + (90 - $this->calcSettings['angle']['isha']) * 240,
        $midnight1,
      );
    }
    else {
      $times = array(
        $basetime,
        $this->fajr($basetime),
        $this->shurooq($basetime),
        $dhohr,
        $this->asr($basetime),
        $this->maghrib($basetime),
        $this->isha($basetime),
        $midnight1,
      );
    }
    $this->latitude = $latitude;

    $adjustMins = array_values($this->calcSettings['adjustMins']);
    for ($i = 0; $i < 6; $i++) {
      $times[$i + 1] += $adjustMins[$i] * 60;
    }

    return $times;
  }

  // Prayer times =============================================================

  private function fajr($basetime) {
    $fajr = $this->midday($basetime);
    $angle = deg2rad($this->calcSettings['angle']['fajr']);
    $fajr -= $this->sunTime($angle, $fajr);
    $fajr -= $this->eot($fajr);
    return $fajr;
  }

  private function shurooq($basetime) {
    $shurooq = $this->midday($basetime);
    $angle = deg2rad(self::sunset);
    $shurooq -= $this->sunTime($angle, $shurooq);
    $shurooq -= $this->eot($shurooq);
    return $shurooq;
  }

  private function dhohr($basetime) {
    $dhohr = $this->midday($basetime);
    $dhohr -= $this->eot($dhohr);
    return $dhohr;
  }

  private function asr($basetime) {
    $asr = $this->midday($basetime);
    $D = $this->declination($asr);
    $F = $this->calcSettings['angle']['asr'] + 1;
    $angle = $this->acot2($F + tan(deg2rad($this->latitude) - $D));
    $asr += $this->sunTime($angle, $asr);
    $asr -= $this->eot($asr);
    return $asr;
  }

  private function maghrib($basetime) {
    $maghrib = $this->midday($basetime);
    $angle = deg2rad(self::sunset);
    $maghrib += $this->sunTime($angle, $maghrib);
    $maghrib -= $this->eot($maghrib);
    return $maghrib;
  }

  private function isha($basetime) {
    $isha = $this->midday($basetime);
    $angle = deg2rad($this->calcSettings['angle']['isha']);
    $isha += $this->sunTime($angle, $isha);
    $isha -= $this->eot($isha);
    $isha += $this->calcSettings['ishaMinutes'] * 60;
    return $isha;
  }

  // Astronomy ================================================================

  private function qiblaAzimuth() {
    $A = deg2rad(self::kaabaLng - $this->longitude);
    $b = deg2rad(90 - $this->latitude);
    $c = deg2rad(90 - self::kaabaLat);

    $C = rad2deg(atan2(sin($A), sin($b) * $this->cot($c) - cos($b) * cos($A)));

    // Azimuth is not negative
    $C += ($C < 0) * 360;

    return $C;
  }

  private function sunAzimuth($time) {
    $T = ($this->unix2JD($time) - self::J2000EpochJD) / 36525;

    // Solar coordinates
    $K = self::earthObl1 + self::earthObl2 * $T;
    $Rs = $this->rightAscension($T);

    // Horizontal coordinates
    $H = deg2rad($this->meanSiderealTime($time) * 15) - $Rs;
    $Ds = $this->declination($time);
    $B = deg2rad($this->latitude);

    // Azimuth
    $A = rad2deg(atan2(-sin($H), tan($Ds) * cos($B) - sin($B) * cos($H)));

    // Azimuth is not negative
    $A += ($A < 0) * 360;

    return $A;
  }

  private function sunAltitude($time) {
    $T = ($this->unix2JD($time) - self::J2000EpochJD) / 36525;

    // Solar coordinates
    $K = self::earthObl1 + self::earthObl2 * $T;
    $Rs = $this->rightAscension($T);

    // Horizontal coordinates
    $H = deg2rad($this->meanSiderealTime($time) * 15) - $Rs;
    $Ds = $this->declination($time);
    $B = deg2rad($this->latitude);

    $h = rad2deg(asin(sin($B) * sin($Ds) + cos($B) * cos($Ds) * cos($H)));

    return $h;
  }

  private function sunTime($angle, $time) {
    $B = deg2rad($this->latitude);
    $sunTime = 0;
    for ($i = 0; $i < 2; $i++) { // 2 iterations are much more accurate than 1
      $D = $this->declination($time + $sunTime);
      $sunTime = acos((sin($angle) - sin($D) * sin($B)) / (cos($D) * cos($B)));
      $sunTime *= 43200 / M_PI;
    }
    return $sunTime;
  }

  private function moonAzimuth($time, $accuracy) {
    $T = ($this->unix2JD($time) - self::J2000EpochJD) / 36525;
    $d = $this->unix2JD($time) - self::J2000EpochJD;
    $T = ($d + 1.5) / 36525; //We must do this due to an error in the algorithm

    if (!is_int($accuracy) || $accuracy < 0 || $accuracy > 3) {
      $accuracy = self::defaultAccuracy;
    }

    // The moon's orbital elements (these are actually 1.5 days off!!)
    $N = 2.183805 - 33.7570736 * $T;
    $i = 0.089804;
    $w = 5.551254 + 104.7747539 * $T;
    $a = 60.2666;
    $e = 0.054900;
    $M = 2.013506 + 8328.69142630 * $T;

    $E = $M + $e * sin($M) * (1 + $e * cos($M));

    if ($accuracy > 0) {
      $delta = 1;
      for ($z = 0; $z < 9 && (abs($delta) > 0.000001); $z++) {
        $delta = $E - $e * sin($E) - $M;
        $delta /= 1 - $e * cos($E);
        $E -= $delta;
      }
    }

    $xv = $a * (cos($E) - $e);
    $yv = $a * sqrt(1 - $e * $e) * sin($E);
    $v = atan2($yv, $xv);
    $r = sqrt($xv * $xv + $yv * $yv);

    $xg = $r * (cos($N) * cos($v + $w) - sin($N) * sin($v + $w) * cos($i));
    $yg = $r * (sin($N) * cos($v + $w) + cos($N) * sin($v + $w) * cos($i));
    $zg = $r * sin($v + $w) * sin($i);

    // If high accuracy is required, compute perbutations
    if ($accuracy > 1) {
      $mLat = atan2($zg, sqrt($xg * $xg + $yg * $yg));
      $mLon = atan2($yg, $xg);

      // Again, these are probably 1.5 days off!!
      $Ms = 4.468863 + 628.3019404 * $T;
      //$Ls = 4.938242 + .0300212 * $T + $Ms;

      $Ls = $this->sunTrueLongitude($T);

      $Lm = $N + $w + $M;
      $D = $Lm - $Ls;
      $F = $Lm - $N;

      $mLat -= .003019 * sin($F - 2 * $D);
      if ($accuracy > 2) {
        $mLat -= .00096 * sin($M - $F - 2 * $D);
        $mLat -= .00080 * sin($M + $F - 2 * $D);
        $mLat += .00058 * sin($F + 2 * $D);
        $mLat += .00030 * sin(2 * $M + $F);
      }

      $mLon -= .02224 * sin($M - 2 * $D);
      $mLon += .0115 * sin(2 * $D);
      $mLon -= .00325 * sin($Ms);
      if ($accuracy > 2) {
        $mLon -= .0010 * sin(2 * $M - 2 * $D);
        $mLon -= .00099 * sin($M - 2 * $D + $Ms);
        $mLon += .00093 * sin($M + 2 * $D);
        $mLon += .00080 * sin(2 * $D - $Ms);
        $mLon += .00072 * sin($M - $Ms);
        $mLon -= .00061 * sin($D);
        $mLon -= .00054 * sin($M + $Ms);
        $mLon -= .00026 * sin(2 * $F - 2 * $D);
        $mLon += .00019 * sin($M - 4 * $D);
      }

      $r -= 0.58 * cos($M - 2 * $D) + 0.46 * cos(2 * $D);

      $xg = $r * cos($mLon) * cos($mLat);
      $yg = $r * sin($mLon) * cos($mLat);
      $zg = $r * sin($mLat);
    }

    $ecl = self::earthObl1 + self::earthObl2 * $T;

    $xe = $xg;
    $ye = $yg * cos($ecl) - $zg * sin($ecl);
    $ze = $yg * sin($ecl) + $zg * cos($ecl);

    $RA = atan2($ye, $xe);
    $Dec = atan2($ze, sqrt($xe * $xe + $ye * $ye));
    $HA = deg2rad($this->meanSiderealTime($time) * 15) - $RA;
    $B = deg2rad($this->latitude);

    // Azimuth
    $A = rad2deg(atan2(-sin($HA), tan($Dec) * cos($B) - sin($B) * cos($HA)));

    // Azimuth is not negative
    $A += ($A < 0) * 360;

    return $A;
  }

  private function eot($time) {
    $T = ($this->unix2JD($time) - self::J2000EpochJD) / 36525;

    // Solar coordinates
    $Lo = self::sunLng1 + self::sunLng2 * $T;
    $Rs = $this->rightAscension($T);

    // Equation of time
    $deltaT = ($Lo - $Rs);
    // -PI <= deltaT < PI
    $deltaT -= floor(($deltaT + M_PI) / M_PI / 2) * 2 * M_PI;
    // Convert radians to seconds
    $deltaT *= 43200 / M_PI;

    return $deltaT;
  }

  private function meanSiderealTime($time) {
    $D = $this->unix2JD($time) - self::J2000EpochJD;

    $Do = floor($D) - 0.5 + ($this->frac($D) >= 0.5);
    $tUT = 24 * ($D - $Do);
    $T = $D / 36525;
    $S = self::earthSrt1 + self::earthSrt2 * $Do + self::earthSrt3 * $tUT +
      self::earthSrt4 * pow($T, 2) + $this->longitude / 15;

    return $S;
  }

  private function declination($time) {
    $T = ($this->unix2JD($time) - self::J2000EpochJD) / 36525;
    $K = self::earthObl1 + self::earthObl2 * $T;
    $Ls = $this->sunTrueLongitude($T);
    $Ds = asin(sin($Ls) * sin($K));
    return $Ds;
  }

  private function rightAscension($T) {
    $Ls = $this->sunTrueLongitude($T);
    $K = self::earthObl1 + self::earthObl2 * $T;
    $Rs = atan(tan($Ls) * cos($K));
    return $Rs;
  }

  private function sunTrueLongitude($T) {
    $Lo = self::sunLng1 + self::sunLng2 * $T;
    $Mo = self::sunAno1 + self::sunAno2 * $T;
    $C = (self::sunCenter1 + self::sunCenter2 * $T) * sin($Mo) +
      self::sunCenter3 * sin(2 * $Mo);
    $Ls = $Lo + $C;
    return $Ls;
  }

  // Date / time ==============================================================

  // Unix timestamp to Julian date
  private function unix2JD($unix) {
    return self::unixEpochJD + $unix / 86400;
  }

  // Julian date to Unix timestamp
  private function JD2unix($JD) {
    return 86400 * ($JD - self::unixEpochJD);
  }

  // Get Unix timestamp for 0:00 of the day we want to calculate
  private function basetime($time) {
    $daybegin = floor($time / 86400) * 86400 - $this->timeZone * 3600;
    $midnight1 = $this->midnight($daybegin);
    $midnight2 = $this->midnight($daybegin + 86400);

    return $daybegin + 86400 * (($time > $midnight2) - ($time < $midnight1));
  }

  private function midday($basetime) {
    return $basetime + (180 + $this->timeZone * 15 - $this->longitude) * 240;
  }

  private function midnight($basetime) {
    $midnight = $basetime + ($this->timeZone * 15 - $this->longitude) * 240;
    return $midnight - $this->eot($midnight);
  }

  // Arithmetic ===============================================================

  // Result is always a positive number
  private function frac($float) {
    return $float - floor($float);
  }

  // Trigonometry =============================================================

  private function cot($rad) {
    return tan(M_PI_2 - $rad);
  }

  private function acot2($rad1, $rad2 = 1) {
    return atan2($rad2, $rad1);
  }

  // Todo =====================================================================

  /*
   - Correct times for high latency
   - Calculate moon visibility
   - Calculate sun visibility
   - Improve documentation, especially for doxygen
   */

  // Notes ====================================================================

  /*

   */

  // Other junk ===============================================================

}
