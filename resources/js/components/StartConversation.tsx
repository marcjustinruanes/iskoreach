import { useForm } from '@inertiajs/react';
import { Button } from './ui/DesignSystem';
import { useRequestFeedback } from './ui/useRequestFeedback';
export default function StartConversation({ applicationId }: { applicationId: number }) {
    const form = useForm({});
    const request = useRequestFeedback(form.cancel);
    return <div className="mt-5"><Button variant="outline" loading={form.processing} onClick={() => { if (!request.begin()) return; form.post('/messages/applications/' + applicationId, { onFinish: request.finish }); }}>Open conversation</Button>{request.error && <p role="alert" className="mt-2 text-sm text-civic-danger">{request.error}</p>}</div>;
}
