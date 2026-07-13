export default function VersionBanner({ version }: { version: string }) {
  return (
    <div className="version-banner" role="note">
      <span aria-hidden="true">📌</span>{" "}
      This documentation covers the latest version (v{version}). Running an
      older release? Open <code>/docs</code> on your own DNS Manager instance
      for the documentation matching your installed version.
    </div>
  );
}
