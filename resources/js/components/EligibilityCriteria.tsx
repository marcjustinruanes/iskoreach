export default function EligibilityCriteria({ value }: { value: unknown }) {
    if (value === null) return <span>Not specified</span>;
    if (typeof value === 'boolean') return <span>{value ? 'Yes' : 'No'}</span>;
    if (Array.isArray(value)) return value.length ? <ul className="list-disc space-y-2 pl-5">{value.map((item, index) => <li key={index}><EligibilityCriteria value={item} /></li>)}</ul> : <span>No entries recorded</span>;
    if (typeof value === 'object') return <dl className="space-y-4">{Object.entries(value).map(([key, item]) => {
        const label = key.replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[_-]+/g, ' ');
        return <div key={key} className="min-w-0"><dt className="break-words font-semibold text-civic-navy">{label.charAt(0).toUpperCase() + label.slice(1)}</dt><dd className="mt-1 whitespace-pre-wrap break-words text-civic-muted [overflow-wrap:anywhere]"><EligibilityCriteria value={item} /></dd></div>;
    })}</dl>;
    return <span className="whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{String(value)}</span>;
}
