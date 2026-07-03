export function HeroLeft() {
  return (
    <div className="flex max-w-3xl flex-col gap-6">
      <div className="text-sm font-medium uppercase tracking-normal text-fd-muted-foreground">
        File-first records for PHP
      </div>
      <h1 className="text-5xl font-semibold tracking-normal text-fd-foreground md:text-7xl">
        storh
      </h1>
      <p className="max-w-2xl text-lg leading-8 text-fd-muted-foreground md:text-xl">
        JSONC documents, append-only segmented logs, and atomic directory queues
        for applications that want durable local storage without a database
        server.
      </p>
    </div>
  );
}
