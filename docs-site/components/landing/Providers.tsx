export default function Providers({ html }: { html: string }) {
  return (
    <section className="mx-auto max-w-5xl px-4 pb-20 sm:px-6">
      <p className="mb-2 text-center text-xs font-semibold uppercase tracking-[0.1em] text-accent">
        Integrations
      </p>
      <h2 className="mb-8 text-center text-2xl font-semibold tracking-tight">
        Supported providers
      </h2>
      <div
        className="prose mx-auto"
        dangerouslySetInnerHTML={{ __html: html }}
      />
    </section>
  );
}
