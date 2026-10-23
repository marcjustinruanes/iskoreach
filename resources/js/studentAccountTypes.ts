export interface SetupStep { key: string; label: string; complete: boolean; url: string }
export interface AccountSetup { steps: SetupStep[]; completed: number; total: number; percentage: number; photo_url: string | null }
