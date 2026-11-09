<?php
/**
 * OPTIONAL - Google Places search for the training venue map picker.
 *
 * The venue map's search box works out of the box with three FREE indexes
 * (OpenStreetMap + Komoot Photon + Wikipedia). That covers most barangays,
 * schools, gyms and landmarks - but NOT everything Google Maps knows
 * (user-uploaded pins, small businesses, very recent landmarks).
 *
 * If you want the search box to find EVERY place Google Maps finds:
 *
 *   1. Go to https://console.cloud.google.com/
 *   2. Create a project -> "APIs & Services" -> enable:
 *        - "Maps JavaScript API"
 *        - "Places API"
 *   3. "Credentials" -> "Create credentials" -> "API key"
 *   4. Paste the key between the quotes below and save the file:
 *
 *        return 'AIzaSy...your-key-here...';
 *
 * Google bills per search, but gives ~USD 200 free credit every month,
 * which is thousands of venue searches - far more than this site will
 * use. Keep billing disabled and Google simply stops working; the map
 * then falls back to the free search automatically (no errors).
 *
 * Leave the string empty ('') to keep the free built-in search.
 */

return '';
