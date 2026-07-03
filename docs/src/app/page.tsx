import { HomePage, CTASection } from "onedocs";
import config from "../../onedocs.config";

export default function Home() {
  return (
    <HomePage config={config}>
      <CTASection
        title="Ready to store records?"
        description="Install the Composer package and create durable file-first records in PHP."
        cta={{ label: "Read the Docs", href: "/docs" }}
      />
    </HomePage>
  );
}
