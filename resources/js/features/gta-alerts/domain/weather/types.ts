/**
 * Domain representation of live weather conditions for a GTA postal area.
 * All fields are camelCase; null indicates data unavailable from provider.
 */
export type WeatherData = {
    fsa: string;
    provider: string;
    temperature: number | null;
    humidity: number | null;
    windSpeed: string | null;
    windDirection: string | null;
    condition: string | null;
    alertLevel: 'yellow' | 'orange' | 'red' | null;
    alertText: string | null;
    fetchedAt: string;
    /** Apparent temperature in °C (Wind Chill or Humidex). */
    feelsLike: number | null;
    /** Dewpoint in °C. */
    dewpoint: number | null;
    /** Atmospheric pressure in kPa. */
    pressure: number | null;
    /** Visibility in km. */
    visibility: number | null;
    /** Wind gust formatted as "N km/h". */
    windGust: string | null;
    /** Pressure tendency e.g. "falling". */
    tendency: string | null;
    /** Name of the observation station. */
    stationName: string | null;
};

/**
 * A resolved GTA location (FSA + human-readable label + centroid coordinates).
 * Stored in localStorage when the user selects their location.
 */
export type WeatherLocation = {
    fsa: string;
    label: string;
    lat: number;
    lng: number;
};
