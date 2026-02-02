export type FireIncident = {
    id: number;
    event_num: string;
    event_type: string;
    prime_street: string | null;
    cross_streets: string | null;
    dispatch_time: string | null;
    alarm_level: number;
    beat: string | null;
    units_dispatched: string | null;
    is_active: boolean;
    feed_updated_at: string | null;
};

export type FireIncidentTypeCount = {
    event_type: string;
    count: number;
};

