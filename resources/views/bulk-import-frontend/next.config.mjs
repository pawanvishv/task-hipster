/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  images: {
    remotePatterns: [
      {
        protocol: 'https',
        hostname: 'task-hipster.test',
        pathname: '/storage/**',
      },
    ],
  },
};

export default nextConfig;
